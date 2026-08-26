<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Features\GroupTalk\GroupTalkNotifyMode;
use App\Services\SnsSettingService;
use App\Support\SettingGroup;
use App\Support\SnsSettingKey;
use App\Support\SurfaceResolver;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
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
 * The group-wide settings: how much of a group's talk this site notifies about
 * (App\Features\GroupTalk\GroupTalkNotifyMode) and what the topic / event boards offer.
 * `sns_settings` is authoritative; a value is stored verbatim on save and resolves to its default
 * while no row exists. The talk default sets a default, not a policy: a member's own catalog row
 * overrides it (docs/internals/notifications.md).
 *
 * @property-read Schema $form
 */
class GroupSettings extends Page
{
    protected static ?int $navigationSort = 17;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedChatBubbleLeftRight;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('%Group% settings');
    }

    public function getTitle(): string|Htmlable
    {
        return __('%Group% settings');
    }

    public function mount(): void
    {
        $this->form->fill($this->currentValues());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([$this->buildTalkSection(), $this->buildBoardSection()])
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
            foreach ($this->keys() as $key) {
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
        foreach ($this->keys() as $key) {
            $values[$key->value] = app(SnsSettingService::class)->get($key);
        }

        return $values;
    }

    /**
     * The keys this page edits. The board switches drive Classic markup only, so on a modern_only
     * install they are neither shown nor rewritten (a stored value survives a later surface change).
     *
     * @return list<SnsSettingKey>
     */
    private function keys(): array
    {
        return [
            ...SnsSettingKey::inGroup(SettingGroup::GroupTalk),
            ...(SurfaceResolver::classicAvailable() ? SnsSettingKey::inGroup(SettingGroup::GroupBoard) : []),
        ];
    }

    private function buildBoardSection(): Section
    {
        $reply = static fn (SnsSettingKey $key): Toggle => Toggle::make($key->value)
            ->label($key->label())
            // Classic-only, and the copy says so (docs/internals/classic-compatibility.md); the
            // section is absent altogether where Classic is never served.
            ->helperText(__('Classic only: each comment gets a Reply link that quotes its number and author into the comment box.'));

        return Section::make(__('%Topics% and events'))
            ->hidden(static fn (): bool => ! SurfaceResolver::classicAvailable())
            ->schema([$reply(SnsSettingKey::GroupTopicCommentReply), $reply(SnsSettingKey::GroupEventCommentReply)]);
    }

    private function buildTalkSection(): Section
    {
        $modes = GroupTalkNotifyMode::cases();

        return Section::make(__('Talk'))
            ->schema([
                Radio::make(SnsSettingKey::GroupTalkNotifyDefault->value)
                    ->label(SnsSettingKey::GroupTalkNotifyDefault->label())
                    ->options($this->byMode(static fn (GroupTalkNotifyMode $mode): string => __($mode->label()), $modes))
                    ->descriptions($this->byMode(static fn (GroupTalkNotifyMode $mode): string => __($mode->description()), $modes))
                    // A member who already made this choice keeps it: the switch moves everyone else.
                    ->helperText(__('This is the default for members who have not decided for themselves.'))
                    ->required(),
            ]);
    }

    /**
     * @param  callable(GroupTalkNotifyMode): string  $text
     * @param  list<GroupTalkNotifyMode>  $modes
     * @return array<string, string>
     */
    private function byMode(callable $text, array $modes): array
    {
        return array_combine(
            array_column($modes, 'value'),
            array_map($text, $modes),
        );
    }
}
