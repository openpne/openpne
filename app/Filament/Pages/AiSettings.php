<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\SnsSettingService;
use App\Support\SettingGroup;
use App\Support\SnsSettingKey;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
 * Whether members may create AI accounts, and how many each may own.
 *
 * Both settings are read at creation time only, which the page says out loud: switching the toggle
 * off closes the door without confiscating what is already behind it, so the accounts members
 * already own stay theirs to manage, empty and delete.
 *
 * @property-read Schema $form
 */
class AiSettings extends Page
{
    protected static ?int $navigationSort = 15;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedCpuChip;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('AI account settings');
    }

    public function getTitle(): string|Htmlable
    {
        return __('AI account settings');
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
            foreach (SnsSettingKey::inGroup(SettingGroup::Ai) as $key) {
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
        foreach (SnsSettingKey::inGroup(SettingGroup::Ai) as $key) {
            $values[$key->value] = app(SnsSettingService::class)->get($key);
        }

        return $values;
    }

    private function buildSection(): Section
    {
        return Section::make()
            ->schema([
                Toggle::make(SnsSettingKey::AiAccountsEnabled->value)
                    ->label(SnsSettingKey::AiAccountsEnabled->label())
                    // Stated here because "allow" reads like a live permission: it is checked when an
                    // account is created and never again, so switching it off leaves the existing
                    // accounts running and their owners still able to empty and delete them.
                    ->helperText(__('Checked only when an account is created. Switching this off stops new ones; the accounts members already own keep working, and their owners can still manage and delete them.')),
                TextInput::make(SnsSettingKey::AiAccountLimit->value)
                    ->label(SnsSettingKey::AiAccountLimit->label())
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->required(),
            ]);
    }
}
