<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Http\Middleware\SetLocale;
use App\Mail\Template\MailTemplate;
use App\Mail\Template\MailTemplateService;
use App\Mail\Template\UnsupportedMailTemplateSyntaxException;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/**
 * Edit the system-mail templates (OpenPNE 3 NotificationMail). The templates are a fixed registry
 * (App\Mail\Template\MailTemplate), not table rows, so this lists them from the registry and edits one at
 * a time in a modal — each template saves on its own, which keeps the editing target obvious and isolates
 * a change to a single template (a long single form made accidental edits easy to miss).
 *
 * A row is persisted only when a field diverges from the built-in default (absence = default), so an
 * untouched template keeps tracking the registry wording and the OpenPNE 3 import stays authoritative.
 * Required/security mails (registration, password, email change) have no toggle — the service always
 * sends them. The body is stored verbatim (line endings normalised to LF).
 */
class MailTemplateSettings extends Page implements HasTable
{
    use InteractsWithTable;

    private const BODY_MAX_BYTES = 65535;

    protected string $view = 'filament.pages.mail-template-settings';

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

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->rows())
            ->columns([
                TextColumn::make('caption')->label(__('Template'))->weight(FontWeight::Bold),
                TextColumn::make('kind')->label(__('Type'))->badge()->color('gray')
                    ->formatStateUsing(fn (string $state): string => $this->kindLabel($state)),
                TextColumn::make('state')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (string $state): string => $this->stateLabel($state))
                    ->color(fn (string $state): string => match ($state) {
                        'sending', 'always' => 'success',
                        'stopped' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('content')->label(__('Content'))->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'customized' ? __('Customized') : __('Default'))
                    ->color(fn (string $state): string => $state === 'customized' ? 'info' : 'gray'),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label(__('Edit'))
                    ->icon(Heroicon::PencilSquare)
                    ->modalHeading(fn (array $record): string => $record['caption'])
                    ->modalSubmitActionLabel(__('Save'))
                    ->fillForm(fn (array $record): array => $this->editFormData($this->template($record)))
                    ->schema(fn (array $record): array => $this->editSchema($this->template($record)))
                    ->action(fn (array $data, array $record): mixed => $this->persist($this->template($record), $data)),
            ])
            ->headerActions([
                Action::make('syntaxHelp')
                    ->label(__('Template format'))
                    ->icon(Heroicon::QuestionMarkCircle)
                    ->color('gray')
                    ->modalHeading(__('Template format'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->schema([
                        Placeholder::make('help')->hiddenLabel()->content(new HtmlString($this->syntaxHelpHtml())),
                    ]),
            ])
            ->paginated(false);
    }

    private function template(array $record): MailTemplate
    {
        return MailTemplate::from($record['__key']);
    }

    /** @return list<array<string, mixed>> one record per registry template, with its status. */
    private function rows(): array
    {
        $parents = DB::table('mail_templates')->get(['id', 'key', 'is_enabled'])->keyBy('key');
        $customized = DB::table('mail_template_translations')->distinct()->pluck('mail_template_id')->flip();

        $rows = [];
        foreach (MailTemplate::cases() as $template) {
            $parent = $parents[$template->value] ?? null;
            $enabled = ! $template->isConfigurable() || $parent === null || (bool) $parent->is_enabled;

            $rows[] = [
                '__key' => $template->value,
                'caption' => $template->caption(),
                'kind' => $template->isConfigurable() ? 'optional' : ($template->isSendable() ? 'required' : 'signature'),
                'state' => $template->isConfigurable()
                    ? ($enabled ? 'sending' : 'stopped')
                    : ($template->isSendable() ? 'always' : 'none'),
                'content' => ($parent !== null && isset($customized[$parent->id])) ? 'customized' : 'default',
            ];
        }

        return $rows;
    }

    private function kindLabel(string $kind): string
    {
        return match ($kind) {
            'required' => __('Required'),
            'optional' => __('Optional'),
            default => __('Signature'),
        };
    }

    private function stateLabel(string $state): string
    {
        return match ($state) {
            'sending' => __('Sending'),
            'stopped' => __('Stopped'),
            'always' => __('Always sent'),
            default => '—',
        };
    }

    /** @return array<string, mixed> current values for the edit modal (enabled + per-locale subject/body). */
    private function editFormData(MailTemplate $template): array
    {
        $parent = DB::table('mail_templates')->where('key', $template->value)->first();
        $tx = [];
        if ($parent !== null) {
            foreach (DB::table('mail_template_translations')->where('mail_template_id', $parent->id)->get() as $row) {
                $tx[$row->locale] = $row;
            }
        }

        $data = [];
        if ($template->isConfigurable()) {
            $data['enabled'] = $parent === null || (bool) $parent->is_enabled;
        }

        foreach (SetLocale::SUPPORTED_LOCALES as $locale) {
            $row = $tx[$locale] ?? null;
            if ($template->isSendable()) {
                $data["{$locale}__subject"] = ($row !== null && $row->subject !== null)
                    ? $row->subject
                    : (string) $template->defaultSubject($locale);
            }
            $data["{$locale}__body"] = $row !== null ? $row->body : $template->defaultBody($locale);
        }

        return $data;
    }

    /** @return list<Component> the edit-modal fields for one template. */
    private function editSchema(MailTemplate $template): array
    {
        $components = [];

        if ($template->isConfigurable()) {
            $components[] = Toggle::make('enabled')->label(__('Send this mail'));
        }

        $components[] = $this->variableHelp($template);

        foreach (SetLocale::SUPPORTED_LOCALES as $locale) {
            $fields = [];

            if ($template->isSendable()) {
                $fields[] = TextInput::make("{$locale}__subject")
                    ->label(__('Subject'))
                    ->maxLength(255)
                    ->rules([$this->syntaxRule($template, $locale, 'subject')]);
            }

            $fields[] = Textarea::make("{$locale}__body")
                ->label(__('Body'))
                ->rows(12)
                ->rules([$this->byteLimitRule(), $this->syntaxRule($template, $locale, 'body')]);

            $components[] = Section::make($this->localeLabel($locale))->schema($fields);
        }

        return $components;
    }

    private function variableHelp(MailTemplate $template): Placeholder
    {
        $lines = [];
        foreach ($template->variableHelp() as $token => $description) {
            $lines[] = '<code>{{ '.e($token).' }}</code> — '.e($description);
        }
        $lines[] = '<code>{{ op_config.sns_name }}</code> — '.e(__('The SNS name (always available).'));

        return Placeholder::make('variables')
            ->label(__('Available variables'))
            ->content(new HtmlString('<div style="line-height:1.8">'.implode('<br>', $lines).'</div>'));
    }

    private function syntaxHelpHtml(): string
    {
        $rows = [
            ['{{ '.__('variable').' }}', __('Insert a value.')],
            ['{% if x == "1" %}…{% endif %}', __('Show a part only when a condition holds (==, !=, <, >, and, or).')],
            ['{% for x in items %}…{% endfor %}', __('Repeat a part for each item.')],
            ["{{ x|date('Y-m-d') }}", __('Format a date.')],
            ["{{ x|default('-') }}", __('Use a fallback when the value is empty.')],
        ];

        $items = array_map(
            static fn (array $r): string => '<li style="margin:.35rem 0"><code>'.e($r[0]).'</code> — '.e($r[1]).'</li>',
            $rows,
        );

        return '<div style="font-size:.875rem"><p>'.e(__('The following template syntax is available:')).'</p>'
            .'<ul style="list-style:disc;padding-left:1.25rem">'.implode('', $items).'</ul></div>';
    }

    private function persist(MailTemplate $template, array $data): void
    {
        DB::transaction(fn () => $this->saveTemplate($template, $data));

        app(MailTemplateService::class)->clearCache();

        Notification::make()->success()->title(__('Saved'))->send();
    }

    /**
     * Keep a row only when the template is off (configurable) or some locale diverges from the default;
     * otherwise drop it so the built-in wording is followed again.
     *
     * @param  array<string, mixed>  $data
     */
    private function saveTemplate(MailTemplate $template, array $data): void
    {
        $enabled = ! $template->isConfigurable() || (bool) ($data['enabled'] ?? true);

        $overrides = [];
        foreach (SetLocale::SUPPORTED_LOCALES as $locale) {
            $body = $this->normalize((string) ($data["{$locale}__body"] ?? ''));
            $subject = $template->isSendable()
                ? trim((string) ($data["{$locale}__subject"] ?? ''))
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

    /** Bounded by bytes, not characters: the body lives in a TEXT column (65535 bytes). */
    private function byteLimitRule(): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            if (strlen((string) $value) > self::BODY_MAX_BYTES) {
                $fail(__('The body is too large.'));
            }
        };
    }

    /**
     * Reject a subject/body the engine cannot send (parse error, sandbox-disallowed tag/filter, unmapped
     * app_url_for route) at save time, so a broken edit never reaches the database and breaks the next
     * mail — including the required ones the service always sends.
     */
    private function syntaxRule(MailTemplate $template, string $locale, string $field): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($template, $locale, $field): void {
            try {
                $service = app(MailTemplateService::class);
                $field === 'subject'
                    ? $service->assertSubjectRenderable($template, $locale, (string) $value)
                    : $service->assertBodyRenderable($template, $locale, (string) $value);
            } catch (UnsupportedMailTemplateSyntaxException $e) {
                $fail(__('This template cannot be sent: :message', ['message' => $e->getMessage()]));
            }
        };
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
