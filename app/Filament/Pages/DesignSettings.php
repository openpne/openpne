<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\HiddenWhenModernOnly;
use App\Services\SnsSettingService;
use App\Support\OperatorContent;
use App\Support\SettingGroup;
use App\Support\SnsSettingKey;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
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
 * Every value is stored verbatim, with no trimming, so a stylesheet's leading @charset and any
 * significant whitespace survive. Which of the HTML and the Markdown keys the page edits follows
 * App\Support\OperatorContent::rawHtml().
 *
 * @property-read Schema $form
 */
class DesignSettings extends Page
{
    use HiddenWhenModernOnly;

    protected static ?int $navigationSort = 6;

    private const PREVIOUS_PREFIX = 'previous_';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedPaintBrush;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Appearance (Classic)');
    }

    public static function getNavigationLabel(): string
    {
        return __('Custom CSS & HTML');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Custom CSS & HTML');
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
            foreach ($this->keys() as $key) {
                DB::table('sns_settings')->updateOrInsert(
                    ['key' => $key->value],
                    ['value' => $key->encode($key->coerce($data[$key->value] ?? ''))],
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
        foreach ($this->keys() as $key) {
            $values[$key->value] = (string) app(SnsSettingService::class)->get($key);
        }
        foreach ($this->previousHtml() as $key => $html) {
            $values[self::PREVIOUS_PREFIX.$key] = $html;
        }

        return $values;
    }

    /**
     * The three design areas as their own sections; every Design-group key lands in exactly one, so
     * the page stays in sync with the registry without a second list.
     *
     * @return list<Section>
     */
    private function buildSections(): array
    {
        $css = [];
        $html = [];
        $footer = [];

        foreach ($this->keys() as $key) {
            $field = $this->field($key);

            if ($key === SnsSettingKey::CustomCss) {
                $css[] = $field;
            } elseif (in_array($key, [SnsSettingKey::FooterBefore, SnsSettingKey::FooterAfter, SnsSettingKey::FooterBeforeMarkdown, SnsSettingKey::FooterAfterMarkdown], true)) {
                $footer[] = $field;
            } else {
                $html[] = $field;
            }
        }

        if (OperatorContent::rawHtml()) {
            return [
                Section::make(__('Custom CSS'))->schema($css),
                Section::make(__('HTML insertion'))->schema($html),
                Section::make(__('Footer'))->schema($footer),
            ];
        }

        $previous = [];
        foreach (array_keys($this->previousHtml()) as $key) {
            // Disabled, so it is never dehydrated and a save cannot write the HTML row.
            $previous[] = Textarea::make(self::PREVIOUS_PREFIX.$key)
                ->label(SnsSettingKey::from($key)->label())
                ->rows(4)
                ->disabled();
        }

        return [
            Section::make(__('Custom CSS'))->schema($css),
            Section::make(__('Page inserts'))->description(__('Markdown is available.'))->schema($html),
            Section::make(__('Footer'))->description(__('Markdown is available.'))->schema($footer),
            Section::make(__('Previous HTML'))
                ->description(__('HTML stored before this site used Markdown. It is not shown on the site.'))
                ->schema($previous)
                ->collapsed()
                ->visible($previous !== []),
        ];
    }

    /**
     * Stored rows only, so a default that was never saved is not shown as previous content.
     *
     * @return array<string, string> the non-empty operator HTML by setting key
     */
    private function previousHtml(): array
    {
        $keys = array_values(array_filter(
            SnsSettingKey::inGroup(SettingGroup::Design),
            static fn (SnsSettingKey $key): bool => $key !== SnsSettingKey::CustomCss && ! $key->isOperatorMarkdown(),
        ));

        return DB::table('sns_settings')
            ->whereIn('key', array_map(static fn (SnsSettingKey $key): string => $key->value, $keys))
            ->where('value', '<>', '')
            ->orderBy('key')
            ->pluck('value', 'key')
            ->map(static fn (mixed $value): string => (string) $value)
            ->all();
    }

    /**
     * The save writes only these, so the values of the mode not in force keep what is stored.
     *
     * @return list<SnsSettingKey>
     */
    private function keys(): array
    {
        $raw = OperatorContent::rawHtml();

        return array_values(array_filter(
            SnsSettingKey::inGroup(SettingGroup::Design),
            static fn (SnsSettingKey $key): bool => $key === SnsSettingKey::CustomCss || $key->isOperatorMarkdown() !== $raw,
        ));
    }

    private function field(SnsSettingKey $key): Textarea
    {
        return Textarea::make($key->value)
            ->label($key->label())
            ->rows($key === SnsSettingKey::CustomCss ? 12 : 4)
            // Bounded by bytes: the TEXT column holds 65535 bytes and a character max would let a
            // multi-byte value overflow it; the no-arg factory makes Filament pass the closure through
            // as a rule, not inject its arguments.
            ->rules([
                fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($key): void {
                    if (strlen((string) $value) > $key->maxBytes()) {
                        $fail(__('The :label value is too large.', ['label' => $key->label()]));
                    }
                },
            ]);
    }
}
