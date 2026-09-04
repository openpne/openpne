<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\SnsSettingService;
use App\Support\SettingGroup;
use App\Support\SnsSettingKey;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;

/**
 * Edit the terms of service and privacy policy shown on the public policy pages. The Markdown is
 * stored verbatim and rendered through the member-body sanitizer, so — like the login screen
 * message and unlike the Classic design slots — this is not an operator-HTML seam.
 *
 * @property-read Schema $form
 */
class SitePolicySettings extends Page
{
    protected static ?int $navigationSort = 13;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedDocumentText;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('Site policy settings');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Site policy settings');
    }

    public function mount(): void
    {
        $this->form->fill($this->currentValues());
    }

    public function form(Schema $schema): Schema
    {
        // One unheaded section: with a field per policy, a heading per policy would only repeat the
        // field's own label (docs/internals/admin-navigation.md).
        return $schema
            ->components([
                Section::make()
                    ->description(__('Markdown is available. Both pages are public — a signed-out visitor can read them.'))
                    ->schema(array_map(
                        fn (SnsSettingKey $key): Textarea => $this->buildField($key),
                        SnsSettingKey::inGroup(SettingGroup::SitePolicy),
                    )),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([$this->getFormContentComponent()]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make([
                    Action::make('save')
                        ->label(__('Save'))
                        ->submit('save')
                        ->keyBindings(['mod+s']),
                ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        DB::transaction(function () use ($data): void {
            foreach (SnsSettingKey::inGroup(SettingGroup::SitePolicy) as $key) {
                DB::table('sns_settings')->updateOrInsert(
                    ['key' => $key->value],
                    ['value' => $key->encode($key->coerce($data[$key->value] ?? ''))],
                );
            }
        });

        app(SnsSettingService::class)->clearCache();

        Notification::make()
            ->success()
            ->title(__('Saved'))
            ->send();

        $this->form->fill($this->currentValues());
    }

    /**
     * @return array<string, string>
     */
    private function currentValues(): array
    {
        $values = [];
        foreach (SnsSettingKey::inGroup(SettingGroup::SitePolicy) as $key) {
            $values[$key->value] = (string) app(SnsSettingService::class)->get($key);
        }

        return $values;
    }

    private function buildField(SnsSettingKey $key): Textarea
    {
        return Textarea::make($key->value)
            ->label($key->label())
            ->rows(15)
            // Bounded by bytes: the TEXT column holds 65535 bytes and a character max would let a
            // multi-byte value overflow it; the no-arg factory makes Filament pass the closure through
            // as a rule, not inject its arguments.
            ->rules([
                fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($key): void {
                    if (strlen((string) $value) > $key->maxBytes()) {
                        $fail(__('The :label value is too large.', ['label' => $key->label()]));
                    }
                },
            ]);
    }
}
