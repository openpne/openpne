<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Http\Middleware\SetLocale;
use App\Services\TermService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
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
 * Edit the term names that are substituted into `%name%` placeholders across
 * the UI. Defaults ship with `lang/{locale}/terms.php`; this page only
 * persists rows that diverge from those defaults.
 *
 * @property-read Schema $form
 */
class TermSettings extends Page
{
    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedTag;
    }

    public static function getNavigationLabel(): string
    {
        return __('Term names');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Term names');
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
            foreach (SetLocale::SUPPORTED_LOCALES as $locale) {
                $defaults = TermService::defaults($locale);
                foreach ($defaults as $name => $default) {
                    $submitted = $data[self::fieldKey($name, $locale)] ?? null;
                    $submitted = is_string($submitted) ? trim($submitted) : $submitted;

                    if ($submitted === null || $submitted === '' || $submitted === $default) {
                        DB::table('term_overrides')
                            ->where('name', $name)
                            ->where('locale', $locale)
                            ->delete();

                        continue;
                    }

                    DB::table('term_overrides')->updateOrInsert(
                        ['name' => $name, 'locale' => $locale],
                        ['value' => $submitted],
                    );
                }
            }
        });

        app(TermService::class)->clearCache();

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
        foreach (SetLocale::SUPPORTED_LOCALES as $locale) {
            $resolved = app(TermService::class)->getTerms($locale);
            foreach (array_keys(TermService::defaults($locale)) as $name) {
                $values[self::fieldKey($name, $locale)] = $resolved[$name] ?? '';
            }
        }

        return $values;
    }

    /**
     * @return list<Section>
     */
    private function buildSections(): array
    {
        $sections = [];
        foreach (SetLocale::SUPPORTED_LOCALES as $locale) {
            $defaults = TermService::defaults($locale);

            $fields = [];
            foreach ($defaults as $name => $default) {
                $fields[] = TextInput::make(self::fieldKey($name, $locale))
                    ->label($name)
                    ->placeholder($default)
                    ->maxLength(255);
            }

            $sections[] = Section::make(self::localeLabel($locale))
                ->description(__('Leave a field blank to fall back to the default value.'))
                ->schema($fields)
                ->collapsible();
        }

        return $sections;
    }

    private static function fieldKey(string $name, string $locale): string
    {
        return "{$locale}__{$name}";
    }

    private static function localeLabel(string $locale): string
    {
        return match ($locale) {
            'ja' => '日本語 (ja)',
            'en' => 'English (en)',
            default => $locale,
        };
    }
}
