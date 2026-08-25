<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\SnsSettingService;
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
 * Turn link preview cards on or off.
 *
 * The one switch here decides whether this site makes outbound requests at all, so the page carries
 * the explanation an operator needs to answer that: the site fetches the pages members link to, for
 * private and friends-only bodies as well as open ones, which tells those destinations that the link
 * was shared here. `sns_settings` is authoritative and the value resolves to its fail-closed default
 * (off) while no row exists.
 *
 * @property-read Schema $form
 */
class LinkCardSettings extends Page
{
    protected static ?int $navigationSort = 14;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedLink;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('Link preview settings');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Link preview settings');
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
            foreach (SnsSettingKey::inGroup(SettingGroup::LinkCard) as $key) {
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
        foreach (SnsSettingKey::inGroup(SettingGroup::LinkCard) as $key) {
            $values[$key->value] = app(SnsSettingService::class)->get($key);
        }

        return $values;
    }

    private function buildSection(): Section
    {
        return Section::make()
            ->schema([
                Toggle::make(SnsSettingKey::LinkCardEnabled->value)
                    ->label(SnsSettingKey::LinkCardEnabled->label())
                    // Stated on the page because it is not what "show previews" implies: the server
                    // reaches out to every linked page, including from bodies only a few people can
                    // read, and the destination learns the link was shared here. What the switch does
                    // *not* govern is worth saying too: a link to one of this site's own pages is
                    // built from the record it names, so it needs no request and no permission.
                    ->helperText(__('This site will request the pages members link to, including from private posts and posts limited to %friends%. Those sites can tell the link was shared here. Links to pages on this site are always previewed, and are never requested.')),
            ]);
    }
}
