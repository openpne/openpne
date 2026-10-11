<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\HiddenWhenModernOnly;
use App\Services\SnsSettingService;
use App\Support\ClassicTheme;
use App\Support\ClassicThemes;
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
 * @property-read Schema $form
 */
class ThemeSettings extends Page
{
    use HiddenWhenModernOnly;

    protected static ?int $navigationSort = 0;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedSwatch;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Appearance (Classic)');
    }

    public static function getNavigationLabel(): string
    {
        return __('Theme');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Theme');
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
        $key = SnsSettingKey::ClassicTheme;

        DB::table('sns_settings')->updateOrInsert(
            ['key' => $key->value],
            ['value' => $key->encode($key->coerce($data[$key->value] ?? ''))],
        );

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
        return [SnsSettingKey::ClassicTheme->value => (string) app(SnsSettingService::class)->get(SnsSettingKey::ClassicTheme)];
    }

    private function buildSection(): Section
    {
        $themes = ClassicThemes::all();
        $options = ['' => __('OpenPNE 3 default skin')] + array_map(static fn (ClassicTheme $theme): string => $theme->name, $themes);

        return Section::make()
            ->schema([
                Radio::make(SnsSettingKey::ClassicTheme->value)
                    ->label(SnsSettingKey::ClassicTheme->label())
                    ->options($options)
                    ->in(array_keys($options))
                    ->descriptions(array_map(
                        static fn (ClassicTheme $theme): string => trim($theme->description.' — '.$theme->author, ' —'),
                        $themes,
                    ))
                    ->helperText(__('A theme restyles the Classic pages only; every phone visitor sees the Modern surface.')),
            ]);
    }
}
