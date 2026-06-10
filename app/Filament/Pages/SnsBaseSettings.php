<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\SnsSettingService;
use App\Support\SettingGroup;
use App\Support\SnsSettingKey;
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
 * Edit the site-wide SNS identity settings (name, title, administrator email). Defaults fall back
 * to env/config; this page only persists rows that diverge from them (an absent row means "follow
 * the default"). The typed registry is App\Support\SnsSettingKey.
 *
 * @property-read Schema $form
 */
class SnsBaseSettings extends Page
{
    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedIdentification;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('SNS base settings');
    }

    public function getTitle(): string|Htmlable
    {
        return __('SNS base settings');
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
            foreach (SnsSettingKey::inGroup(SettingGroup::Base) as $key) {
                $submitted = $data[$key->value] ?? null;
                $trimmed = is_string($submitted) ? trim($submitted) : $submitted;

                if ($trimmed === null || $trimmed === '' || $key->isDefault($trimmed)) {
                    DB::table('sns_settings')->where('key', $key->value)->delete();

                    continue;
                }

                DB::table('sns_settings')->updateOrInsert(
                    ['key' => $key->value],
                    ['value' => $key->encode($key->coerce($trimmed))],
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
        foreach (SnsSettingKey::inGroup(SettingGroup::Base) as $key) {
            $values[$key->value] = (string) app(SnsSettingService::class)->get($key);
        }

        return $values;
    }

    private function buildSection(): Section
    {
        $fields = [];
        foreach (SnsSettingKey::inGroup(SettingGroup::Base) as $key) {
            $input = TextInput::make($key->value)
                ->label($key->label())
                ->placeholder((string) $key->default())
                ->maxLength($key->maxLength());

            if ($key->isRequired()) {
                $input->required();
            }

            if ($key->isEmail()) {
                $input->email();
            }

            $fields[] = $input;
        }

        return Section::make(__('SNS base settings'))
            ->description(__('Leave a field blank to fall back to the default value.'))
            ->schema($fields);
    }
}
