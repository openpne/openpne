<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\IndicatesClassicSurface;
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
 * Edit the OpenPNE 3 design customizations reflected in the Classic shell: custom CSS, the PC HTML
 * insertion slots, and the footer HTML (logged-out / logged-in). `sns_settings` is authoritative —
 * every field is stored verbatim, with no trimming, so a stylesheet's leading @charset and any
 * significant whitespace survive. The typed registry is App\Support\SnsSettingKey (Design group).
 *
 * The values are emitted raw into the Classic page (admin-trusted operator HTML/CSS, e.g. analytics
 * tags); the write path is the admin panel only.
 *
 * @property-read Schema $form
 */
class DesignSettings extends Page
{
    use IndicatesClassicSurface;

    protected static ?int $navigationSort = 6;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedPaintBrush;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Appearance');
    }

    public static function getNavigationLabel(): string
    {
        return __('Design settings');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Design settings');
    }

    public function mount(): void
    {
        $this->form->fill($this->currentValues());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components($this->buildSections())
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
            foreach (SnsSettingKey::inGroup(SettingGroup::Design) as $key) {
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
        foreach (SnsSettingKey::inGroup(SettingGroup::Design) as $key) {
            $values[$key->value] = (string) app(SnsSettingService::class)->get($key);
        }

        return $values;
    }

    /**
     * The three design areas as their own sections; every Design-group key lands in exactly one, so
     * the page stays in sync with the registry without a second list.
     *
     * @return list<Section>
     */
    private function buildSections(): array
    {
        $css = [];
        $html = [];
        $footer = [];

        foreach (SnsSettingKey::inGroup(SettingGroup::Design) as $key) {
            $field = $this->field($key);

            if ($key === SnsSettingKey::CustomCss) {
                $css[] = $field;
            } elseif (in_array($key, [SnsSettingKey::FooterBefore, SnsSettingKey::FooterAfter], true)) {
                $footer[] = $field;
            } else {
                $html[] = $field;
            }
        }

        return [
            Section::make(__('Custom CSS'))->schema($css),
            Section::make(__('HTML insertion'))->schema($html),
            Section::make(__('Footer'))->schema($footer),
        ];
    }

    private function field(SnsSettingKey $key): Textarea
    {
        return Textarea::make($key->value)
            ->label($key->label())
            ->rows($key === SnsSettingKey::CustomCss ? 12 : 4)
            // Bounded by bytes, not characters: the value lives in a TEXT column (65535 bytes), and a
            // char-count max would let a multi-byte value overflow it. Wrapped in a no-arg factory so
            // Filament passes the closure through as a validation rule instead of trying to inject its
            // ($attribute, $value, $fail) arguments.
            ->rules([
                fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($key): void {
                    if (strlen((string) $value) > $key->maxBytes()) {
                        $fail(__('The :label value is too large.', ['label' => $key->label()]));
                    }
                },
            ]);
    }
}
