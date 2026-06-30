<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Http\Middleware\SetLocale;
use App\Mail\Template\MailTemplate;
use App\Mail\Template\MailTemplateService;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
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
 * Edit the system-mail templates (OpenPNE 3 NotificationMail): per-locale subject/body and, for the
 * configurable ones, an on/off switch. The built-in wording lives in the App\Mail\Template\MailTemplate
 * registry; this page only persists a row when a field diverges from that default (absence = default), so
 * an untouched template keeps tracking the built-in wording and the OpenPNE 3 import stays authoritative.
 *
 * Required/security mails (registration, password, email change) have no toggle — the service always
 * sends them. The body is stored verbatim (line endings normalised to LF), like the design slots.
 *
 * @property-read Schema $form
 */
class MailTemplateSettings extends Page
{
    private const BODY_MAX_BYTES = 65535;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedEnvelope;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('Mail templates');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Mail templates');
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
            foreach (MailTemplate::cases() as $template) {
                $this->saveTemplate($template, $data);
            }
        });

        app(MailTemplateService::class)->clearCache();

        Notification::make()
            ->success()
            ->title(__('Saved'))
            ->send();

        $this->form->fill($this->currentValues());
    }

    /**
     * Persist one template: keep a row only when it is off (configurable) or some locale diverges from the
     * default; otherwise drop the row so the built-in wording is followed again.
     *
     * @param  array<string, mixed>  $data
     */
    private function saveTemplate(MailTemplate $template, array $data): void
    {
        $enabled = ! $template->isConfigurable()
            || (bool) ($data[$this->fieldKey($template, 'enabled')] ?? true);

        $overrides = [];
        foreach (SetLocale::SUPPORTED_LOCALES as $locale) {
            $body = $this->normalize((string) ($data[$this->fieldKey($template, "{$locale}__body")] ?? ''));
            $subject = $template->isSendable()
                ? trim((string) ($data[$this->fieldKey($template, "{$locale}__subject")] ?? ''))
                : null;

            $isDefault = $body === $template->defaultBody($locale)
                && ($subject ?? '') === (string) $template->defaultSubject($locale);

            if (! $isDefault) {
                $overrides[$locale] = ['subject' => $subject, 'body' => $body];
            }
        }

        $id = DB::table('mail_templates')->where('key', $template->value)->value('id');

        if ($enabled && $overrides === []) {
            if ($id !== null) {
                DB::table('mail_template_translations')->where('mail_template_id', $id)->delete();
                DB::table('mail_templates')->where('id', $id)->delete();
            }

            return;
        }

        DB::table('mail_templates')->updateOrInsert(['key' => $template->value], ['is_enabled' => $enabled]);
        $id = DB::table('mail_templates')->where('key', $template->value)->value('id');

        foreach (SetLocale::SUPPORTED_LOCALES as $locale) {
            if (isset($overrides[$locale])) {
                DB::table('mail_template_translations')->updateOrInsert(
                    ['mail_template_id' => $id, 'locale' => $locale],
                    $overrides[$locale],
                );
            } else {
                DB::table('mail_template_translations')
                    ->where('mail_template_id', $id)
                    ->where('locale', $locale)
                    ->delete();
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function currentValues(): array
    {
        $parents = DB::table('mail_templates')->get(['id', 'key', 'is_enabled'])->keyBy('key');

        $idToKey = [];
        foreach ($parents as $parent) {
            $idToKey[$parent->id] = $parent->key;
        }

        $tx = [];
        if ($parents->isNotEmpty()) {
            $rows = DB::table('mail_template_translations')
                ->whereIn('mail_template_id', $parents->pluck('id')->all())
                ->get(['mail_template_id', 'locale', 'subject', 'body']);
            foreach ($rows as $row) {
                $tx[$idToKey[$row->mail_template_id]][$row->locale] = $row;
            }
        }

        $values = [];
        foreach (MailTemplate::cases() as $template) {
            if ($template->isConfigurable()) {
                $parent = $parents[$template->value] ?? null;
                $values[$this->fieldKey($template, 'enabled')] = $parent === null || (bool) $parent->is_enabled;
            }

            foreach (SetLocale::SUPPORTED_LOCALES as $locale) {
                $row = $tx[$template->value][$locale] ?? null;

                if ($template->isSendable()) {
                    $values[$this->fieldKey($template, "{$locale}__subject")] =
                        ($row !== null && $row->subject !== null) ? $row->subject : (string) $template->defaultSubject($locale);
                }

                $values[$this->fieldKey($template, "{$locale}__body")] =
                    $row !== null ? $row->body : $template->defaultBody($locale);
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

        foreach (MailTemplate::cases() as $template) {
            $components = [];

            if ($template->isConfigurable()) {
                $components[] = Toggle::make($this->fieldKey($template, 'enabled'))
                    ->label(__('Send this mail'));
            }

            $components[] = Placeholder::make($this->fieldKey($template, 'variables'))
                ->label(__('Available variables'))
                ->content($this->variableHint($template));

            foreach (SetLocale::SUPPORTED_LOCALES as $locale) {
                $fields = [];

                if ($template->isSendable()) {
                    $fields[] = TextInput::make($this->fieldKey($template, "{$locale}__subject"))
                        ->label(__('Subject'))
                        ->maxLength(255);
                }

                $fields[] = Textarea::make($this->fieldKey($template, "{$locale}__body"))
                    ->label(__('Body'))
                    ->rows(10)
                    ->rules([$this->byteLimitRule()]);

                $components[] = Section::make($this->localeLabel($locale))->schema($fields);
            }

            $sections[] = Section::make($template->caption())
                ->schema($components)
                ->collapsible()
                ->collapsed();
        }

        return $sections;
    }

    /** The `{{ … }}` tokens a template may use, with the always-available SNS name appended. */
    private function variableHint(MailTemplate $template): string
    {
        $tokens = array_map(static fn (string $name): string => "{{ {$name} }}", $template->variables());
        $tokens[] = '{{ op_config.sns_name }}';

        return implode('   ', $tokens);
    }

    /** Bounded by bytes, not characters: the body lives in a TEXT column (65535 bytes). */
    private function byteLimitRule(): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            if (strlen((string) $value) > self::BODY_MAX_BYTES) {
                $fail(__('The body is too large.'));
            }
        };
    }

    private function fieldKey(MailTemplate $template, string $suffix): string
    {
        // The key value carries hyphens (friend-accepted); fold them so the field name is a safe Livewire
        // statePath segment. Every read uses this helper, so the name is never reverse-parsed.
        return str_replace('-', '_', $template->value)."__{$suffix}";
    }

    private function normalize(string $value): string
    {
        return str_replace("\r\n", "\n", $value);
    }

    private function localeLabel(string $locale): string
    {
        return match ($locale) {
            'ja' => '日本語 (ja)',
            'en' => 'English (en)',
            default => $locale,
        };
    }
}
