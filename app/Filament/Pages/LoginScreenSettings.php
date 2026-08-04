<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\SnsSettingService;
use App\Support\SettingGroup;
use App\Support\SnsSettingKey;
use App\Support\SurfaceResolver;
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
 * Edit the message shown above the sign-in form on the Modern login screen — where a site says what
 * it is to someone who has not signed in yet. `sns_settings` is authoritative and the Markdown is
 * stored verbatim; the login screen renders it through the member-body sanitizer, so unlike the
 * Classic design slots this is not an operator-HTML seam.
 *
 * @property-read Schema $form
 */
class LoginScreenSettings extends Page
{
    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedArrowRightEndOnRectangle;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('Login page');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Login page');
    }

    public function mount(): void
    {
        $this->form->fill($this->currentValues());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([$this->buildSection()])
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
            foreach (SnsSettingKey::inGroup(SettingGroup::LoginScreen) as $key) {
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
        foreach (SnsSettingKey::inGroup(SettingGroup::LoginScreen) as $key) {
            $values[$key->value] = (string) app(SnsSettingService::class)->get($key);
        }

        return $values;
    }

    private function buildSection(): Section
    {
        $key = SnsSettingKey::LoginMessage;

        return Section::make($key->label())
            ->schema([
                Textarea::make($key->value)
                    ->label($key->label())
                    ->rows(10)
                    // With Classic available the copy has to say which login screen this reaches, and
                    // point at the gadget editor for the other one (docs/internals/classic-compatibility.md);
                    // on a modern_only install the operator never sees Classic, so it must not mention surfaces.
                    ->helperText(SurfaceResolver::classicAvailable()
                        ? __('Markdown is available. It is shown above the form on the Modern login screen; the Classic login screen is edited under Appearance (Classic) > Gadgets.')
                        : __('Markdown is available. It is shown above the form on the login screen.'))
                    // Bounded by bytes, not characters: the value lives in a TEXT column (65535 bytes),
                    // and a char-count max would let a multi-byte value overflow it. Wrapped in a no-arg
                    // factory so Filament passes the closure through as a validation rule instead of
                    // trying to inject its ($attribute, $value, $fail) arguments.
                    ->rules([
                        fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($key): void {
                            if (strlen((string) $value) > $key->maxBytes()) {
                                $fail(__('The :label value is too large.', ['label' => $key->label()]));
                            }
                        },
                    ]),
            ]);
    }
}
