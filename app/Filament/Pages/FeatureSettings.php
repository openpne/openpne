<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\SnsSettingService;
use App\Support\Feature;
use App\Support\SettingGroup;
use App\Support\SnsSettingKey;
use BackedEnum;
use Filament\Actions\Action;
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
 * Switch the feature units (App\Support\Feature) on and off site-wide; see
 * docs/internals/feature-toggles.md. `sns_settings` is authoritative; every key is stored verbatim
 * on save and resolves to enabled while no row exists, so an install that never opened this page
 * runs every unit.
 *
 * @property-read Schema $form
 */
class FeatureSettings extends Page
{
    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedPuzzlePiece;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('Features');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Features');
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
            foreach (SnsSettingKey::inGroup(SettingGroup::Features) as $key) {
                DB::table('sns_settings')->updateOrInsert(
                    ['key' => $key->value],
                    ['value' => $key->encode($key->coerce($data[$key->value] ?? $key->default()))],
                );
            }
        });

        // Only the settings cache: the navigation and gadget row caches never embed feature state,
        // so a switched unit takes effect without clearing them.
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
        foreach (SnsSettingKey::inGroup(SettingGroup::Features) as $key) {
            $values[$key->value] = app(SnsSettingService::class)->get($key);
        }

        return $values;
    }

    private function buildSection(): Section
    {
        $fields = [];
        foreach (Feature::cases() as $feature) {
            $toggle = Toggle::make($feature->settingKey()->value)
                ->label($feature->label());

            // A nested unit's own switch reads as broken while its parent is off (it changes nothing
            // that is reachable), so the dependency is stated where the operator flips it.
            if (($parent = $feature->parent()) !== null) {
                $toggle->helperText(__('Part of :parent. This switch has no effect while :parent is off.', ['parent' => $parent->label()]));
            }

            $fields[] = $toggle;
        }

        return Section::make(__('Features'))
            ->description(__('A switched-off feature answers 404 and disappears from navigation and feeds. Nothing is deleted: switching it back on restores the feature as it was.'))
            ->schema($fields);
    }
}
