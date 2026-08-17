<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\SnsSettingService;
use App\Support\Look;
use App\Support\LookResolver;
use App\Support\PreferenceKey;
use App\Support\SettingGroup;
use App\Support\SnsSettingKey;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
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
 * Pick the look the Modern surface renders (App\Support\Look) and which looks members may pick for
 * themselves. `sns_settings` is authoritative; the value is stored verbatim on save and resolves to
 * `standard` while no row exists.
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
        $released = 0;

        DB::transaction(function () use ($data, &$released): void {
            foreach (SnsSettingKey::inGroup(SettingGroup::Look) as $key) {
                DB::table('sns_settings')->updateOrInsert(
                    ['key' => $key->value],
                    ['value' => $key->encode($key->coerce($data[$key->value] ?? $key->default()))],
                );
            }

            $released = $this->releaseMembersOutside($data);
        });

        app(SnsSettingService::class)->clearCache();

        $saved = Notification::make()->success()->title(__('Saved'));
        if ($released > 0) {
            // Narrowing the set moves members off their choice, so the save reports how many rather
            // than leaving it to be discovered.
            $saved->body(__('Cleared the layout choice of :count members', ['count' => $released]));
        }
        $saved->send();

        $this->form->fill($this->currentValues());
    }

    /**
     * Drop every member choice this save leaves unoffered, returning them to the site default.
     * Derived from the POSTED values — the set the save is establishing, which is not readable from
     * the settings service yet — and run inside the same transaction, so no request can resolve
     * against the new set while a stale row survives.
     *
     * @param  array<string, mixed>  $data
     */
    private function releaseMembersOutside(array $data): int
    {
        $selectable = LookResolver::selectableAmong(
            SnsSettingKey::SelectableLooks->coerce($data[SnsSettingKey::SelectableLooks->value] ?? []),
            SnsSettingKey::DefaultLook->coerce($data[SnsSettingKey::DefaultLook->value] ?? SnsSettingKey::DefaultLook->default()),
        );

        return DB::table('member_preferences')
            ->where('key', PreferenceKey::PreferredLook->value)
            ->whereNotIn('value', array_column($selectable, 'value'))
            ->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function currentValues(): array
    {
        $values = [];
        foreach (SnsSettingKey::inGroup(SettingGroup::Look) as $key) {
            // The service hands back typed looks; the controls hold and post the stored ids.
            $value = app(SnsSettingService::class)->get($key);
            $values[$key->value] = match (true) {
                $value instanceof Look => $value->value,
                is_array($value) => array_column($value, 'value'),
                default => $value,
            };
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
                // Filament validates each ticked value against these option keys (a per-item `in`
                // rule), so the accepted ids and the offered ones are the same list by construction.
                CheckboxList::make(SnsSettingKey::SelectableLooks->value)
                    ->label(SnsSettingKey::SelectableLooks->label())
                    ->options($this->byLook(static fn (Look $look): string => __($look->label()), $looks))
                    ->helperText(__('The site default can always be selected. Removing a layout returns the members who chose it to the site default.')),
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
