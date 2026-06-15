<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Gadgets\GadgetLayout;
use App\Services\GadgetService;
use App\Services\SnsSettingService;
use App\Support\SettingGroup;
use App\Support\SnsSettingKey;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
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
 * Pick the Classic layout (which zones are active) per page. The choice is a SnsSettingKey in the
 * GadgetLayout group, stored in sns_settings; saving clears both the settings cache and the gadget
 * cache (the active layout decides which zones GadgetService renders).
 *
 * @property-read Schema $form
 */
class GadgetLayoutSettings extends Page
{
    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedRectangleGroup;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('Gadget layout');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Gadget layout');
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
            foreach (SnsSettingKey::inGroup(SettingGroup::GadgetLayout) as $key) {
                DB::table('sns_settings')->updateOrInsert(
                    ['key' => $key->value],
                    ['value' => $key->encode($key->coerce($data[$key->value] ?? ''))],
                );
            }
        });

        app(SnsSettingService::class)->clearCache();
        app(GadgetService::class)->clearCache();

        Notification::make()
            ->success()
            ->title(__('Saved'))
            ->send();

        $this->form->fill($this->currentValues());
    }

    /** @return array<string, string> */
    private function currentValues(): array
    {
        $values = [];
        foreach (SnsSettingKey::inGroup(SettingGroup::GadgetLayout) as $key) {
            $values[$key->value] = (string) app(SnsSettingService::class)->get($key);
        }

        return $values;
    }

    private function buildSection(): Section
    {
        $fields = [];
        foreach (SnsSettingKey::inGroup(SettingGroup::GadgetLayout) as $key) {
            $fields[] = Select::make($key->value)
                ->label($key->label())
                ->options(self::layoutOptions())
                ->selectablePlaceholder(false)
                ->required();
        }

        return Section::make(__('Gadget layout'))->schema($fields);
    }

    /** @return array<string, string> layout => "Layout X (its zones)". */
    private static function layoutOptions(): array
    {
        $options = [];
        foreach (['layoutA', 'layoutB', 'layoutC'] as $layout) {
            $options[$layout] = 'Layout '.strtoupper(substr($layout, -1)).' ('.implode(', ', GadgetLayout::zones($layout)).')';
        }

        return $options;
    }
}
