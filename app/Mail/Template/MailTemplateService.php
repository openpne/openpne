<?php

declare(strict_types=1);

namespace App\Mail\Template;

use App\Services\TermService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MailTemplateService
{
    private const CACHE_KEY = 'mail_templates';

    private const CACHE_TTL = 3600;

    public function __construct(private readonly MailTemplateRenderer $renderer) {}

    /**
     * Callers pass only the template-specific context: the OpenPNE 3 globals (op_config, op_term,
     * sf_config) are injected here, resolved at render time rather than lazily.
     *
     * @param  array<string, mixed>  $context
     */
    public function render(MailTemplate $template, string $locale, array $context = []): RenderedMailTemplate
    {
        $context = $this->baseContext($locale) + $context;

        $subjectTpl = $this->subjectTemplate($template, $locale);
        $subject = $subjectTpl !== null ? $this->renderer->renderSubject($subjectTpl, $context) : '';
        $body = $this->renderer->render($this->bodyTemplate($template, $locale), $context);

        if ($template->isSendable()) {
            $signature = $this->renderSignature($locale);
            if ($signature !== '') {
                $body .= "\n\n".$signature;
            }
        }

        return new RenderedMailTemplate($subject, $body);
    }

    /**
     * Throws UnsupportedMailTemplateSyntaxException so the editor can reject what the admin typed before it
     * is stored and breaks the next send.
     */
    public function assertSubjectRenderable(MailTemplate $template, string $locale, string $subject): void
    {
        $this->renderer->renderSubject($subject, $this->validationContext($template, $locale));
    }

    public function assertBodyRenderable(MailTemplate $template, string $locale, string $body): void
    {
        $this->renderer->render($body, $this->validationContext($template, $locale));
    }

    /** @return array<string, mixed> */
    private function validationContext(MailTemplate $template, string $locale): array
    {
        return $this->baseContext($locale) + $template->representativeContext();
    }

    public function isEnabled(MailTemplate $template): bool
    {
        if (! $template->isConfigurable()) {
            return true;
        }

        return $this->map()['enabled'][$template->value] ?? true;
    }

    /** Every writer of `mail_templates` must call this; the map is otherwise cached for an hour. */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function renderSignature(string $locale): string
    {
        $body = $this->bodyTemplate(MailTemplate::Signature, $locale);

        // OpenPNE 3 renders the signature with no body params — base context only.
        return $body === '' ? '' : $this->renderer->render($body, $this->baseContext($locale));
    }

    /** @return array<string, mixed> */
    private function baseContext(string $locale): array
    {
        return [
            'op_config' => [
                'sns_name' => sns_name(),
                'admin_mail_address' => sns_admin_mail_address(),
            ],
            'op_term' => app(TermService::class)->getTerms($locale),
            'sf_config' => ['op_base_url' => url('/')],
        ];
    }

    private function subjectTemplate(MailTemplate $template, string $locale): ?string
    {
        return $this->override($template, $locale, 'subject') ?? $template->defaultSubject($locale);
    }

    private function bodyTemplate(MailTemplate $template, string $locale): string
    {
        return $this->override($template, $locale, 'body') ?? $template->defaultBody($locale);
    }

    /**
     * A row's absence is what selects the default: a present row is honoured as-is, an empty body included.
     * Per locale — a ja recipient never falls back to an en override, and an unedited locale uses its own
     * default, which the OpenPNE 3 import populates for both.
     */
    private function override(MailTemplate $template, string $locale, string $field): ?string
    {
        $row = $this->map()['tx'][$template->value][$this->localeKey($locale)] ?? null;

        return $row === null ? null : $row[$field];
    }

    private function localeKey(string $locale): string
    {
        return str_starts_with($locale, 'ja') ? 'ja' : 'en';
    }

    /**
     * @return array{enabled: array<string, bool>, tx: array<string, array<string, array{subject: ?string, body: string}>>}
     */
    private function map(): array
    {
        // Outside the cache: a pre-migrate / pre-seed call must resolve to defaults WITHOUT caching the
        // empty map (which would then hide the first rows until clearCache()).
        if (! Schema::hasTable('mail_templates')) {
            return ['enabled' => [], 'tx' => []];
        }

        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
            $byId = [];
            $enabled = [];
            foreach (DB::table('mail_templates')->get(['id', 'key', 'is_enabled']) as $row) {
                $byId[$row->id] = $row->key;
                $enabled[$row->key] = (bool) $row->is_enabled;
            }

            $tx = [];
            if ($byId !== []) {
                $rows = DB::table('mail_template_translations')
                    ->whereIn('mail_template_id', array_keys($byId))
                    ->get(['mail_template_id', 'locale', 'subject', 'body']);
                foreach ($rows as $row) {
                    $tx[$byId[$row->mail_template_id]][$row->locale] = [
                        'subject' => $row->subject,
                        'body' => $row->body,
                    ];
                }
            }

            return ['enabled' => $enabled, 'tx' => $tx];
        });
    }
}
