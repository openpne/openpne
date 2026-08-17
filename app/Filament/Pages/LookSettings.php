<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\SnsSettingService;
use App\Support\Look;
use App\Support\SettingGroup;
use App\Support\SnsSettingKey;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
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
 * Pick the look the Modern surface renders (App\Support\Look). `sns_settings` is authoritative; the
 * value is stored verbatim on save and resolves to `standard` while no row exists.
 *
 * @property-read Schema $form
 */
class LookSettings extends Page
{
    protected static ?int $navigationSort = 16;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedViewColumns;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('UI layout settings');
    }

    public function getTitle(): string|Htmlable
    {
        return __('UI layout settings');
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
            foreach (SnsSettingKey::inGroup(SettingGroup::Look) as $key) {
                DB::table('sns_settings')->updateOrInsert(
                    ['key' => $key->value],
                    ['value' => $key->encode($key->coerce($data[$key->value] ?? $key->default()))],
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
     * @return array<string, mixed>
     */
    private function currentValues(): array
    {
        $values = [];
        foreach (SnsSettingKey::inGroup(SettingGroup::Look) as $key) {
            // The service hands back the typed enum; the radio holds and posts the stored id.
            $value = app(SnsSettingService::class)->get($key);
            $values[$key->value] = $value instanceof Look ? $value->value : $value;
        }

        return $values;
    }

    private function buildSection(): Section
    {
        $looks = Look::cases();

        return Section::make()
            ->schema([
                Radio::make(SnsSettingKey::DefaultLook->value)
                    ->label(SnsSettingKey::DefaultLook->label())
                    ->options($this->byLook(static fn (Look $look): string => __($look->label()), $looks))
                    ->descriptions($this->byLook(static fn (Look $look): string => __($look->description()), $looks))
                    ->required(),
            ]);
    }

    /**
     * @param  callable(Look): string  $text
     * @param  list<Look>  $looks
     * @return array<string, string>
     */
    private function byLook(callable $text, array $looks): array
    {
        return array_combine(
            array_column($looks, 'value'),
            array_map($text, $looks),
        );
    }
}
