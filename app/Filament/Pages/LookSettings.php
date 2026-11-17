<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\HiddenWhenClassicOnly;
use App\Services\SnsSettingService;
use App\Support\ModernGroupList;
use App\Support\ModernHome;
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
 * Pick the page `/` serves (App\Support\ModernHome) and what a joined group's row opens
 * (App\Support\ModernGroupList). `sns_settings` is authoritative; each value is stored verbatim on
 * save and resolves to its registry default while no row exists.
 *
 * @property-read Schema $form
 */
class LookSettings extends Page
{
    use HiddenWhenClassicOnly;

    protected static ?int $navigationSort = 16;

    /** The keys this page writes: the look keys share their setting group and are no longer offered. */
    private const KEYS = [SnsSettingKey::ModernHome, SnsSettingKey::ModernGroupList];

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
        return __('Home and %community% entry settings');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Home and %community% entry settings');
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
            foreach (self::KEYS as $key) {
                DB::table('sns_settings')->updateOrInsert(
                    ['key' => $key->value],
                    ['value' => $key->encode($key->coerce($data[$key->value] ?? $key->default()))],
                );
            }
        });

        app(SnsSettingService::class)->clearCache();

        Notification::make()->success()->title(__('Saved'))->send();

        $this->form->fill($this->currentValues());
    }

    /**
     * @return array<string, mixed>
     */
    private function currentValues(): array
    {
        $values = [];
        foreach (self::KEYS as $key) {
            // The service hands back typed values; the controls hold and post the stored ids.
            $value = app(SnsSettingService::class)->get($key);
            $values[$key->value] = $value instanceof ModernHome || $value instanceof ModernGroupList ? $value->value : $value;
        }

        return $values;
    }

    private function buildSection(): Section
    {
        return Section::make()
            ->schema([
                Radio::make(SnsSettingKey::ModernHome->value)
                    ->label(SnsSettingKey::ModernHome->label())
                    ->options(array_combine(
                        array_column(ModernHome::cases(), 'value'),
                        array_map(static fn (ModernHome $home): string => __($home->label()), ModernHome::cases()),
                    ))
                    ->descriptions(array_combine(
                        array_column(ModernHome::cases(), 'value'),
                        array_map(static fn (ModernHome $home): string => __($home->description()), ModernHome::cases()),
                    ))
                    ->helperText(__('The other page stays in the menu.'))
                    ->required(),
                Radio::make(SnsSettingKey::ModernGroupList->value)
                    ->label(SnsSettingKey::ModernGroupList->label())
                    ->options(array_combine(
                        array_column(ModernGroupList::cases(), 'value'),
                        array_map(static fn (ModernGroupList $list): string => __($list->label()), ModernGroupList::cases()),
                    ))
                    ->descriptions(array_combine(
                        array_column(ModernGroupList::cases(), 'value'),
                        array_map(static fn (ModernGroupList $list): string => __($list->description()), ModernGroupList::cases()),
                    ))
                    ->required(),
            ]);
    }
}
