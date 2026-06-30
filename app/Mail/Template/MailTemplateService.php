<?php

declare(strict_types=1);

namespace App\Mail\Template;

use App\Services\TermService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves a mail template to its rendered subject/body for a recipient, reading the admin override from
 * `mail_templates` (+ translations) and falling back to the MailTemplate registry default. Mirrors
 * SnsSettingService: a single cached map guarded by Schema::hasTable so a pre-migrate / console boot
 * resolves to defaults instead of throwing; the admin editor calls clearCache() after saving.
 */
class MailTemplateService
{
    private const CACHE_KEY = 'mail_templates';

    private const CACHE_TTL = 3600;

    public function __construct(private readonly MailTemplateRenderer $renderer) {}

    /**
     * Render a template for a recipient. The OpenPNE 3 globals (op_config, op_term, sf_config) are
     * injected here so callers pass only the template-specific context; values resolve now (at render
     * time), not lazily. The signature is appended to a sendable body.
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

    /** Whether a template is sent. Non-configurable templates are always on; configurable ones honor the stored flag. */
    public function isEnabled(MailTemplate $template): bool
    {
        if (! $template->isConfigurable()) {
            return true;
        }

        return $this->map()['enabled'][$template->value] ?? true;
    }

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

    /**
     * The OpenPNE 3 globals every template may reference, as nested arrays so `{{ op_config.sns_name }}`
     * and `{{ op_term.friend }}` resolve. Resolved now (at render time), not lazily.
     *
     * @return array<string, mixed>
     */
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
     * Stored override for a (template, locale, field), or null to use the default. "Use the default" is
     * expressed by the row's ABSENCE, not by an empty value: a present row is honored as-is, including an
     * empty body (e.g. an admin who blanked the signature wants no signature, not the default restored).
     * Resolved per locale — a ja recipient never falls back to an en override (that would mail the wrong
     * language); an unedited locale uses its own default, which the OpenPNE 3 import populates for both.
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
     * Cached snapshot: per-key is_enabled and per-(key, locale) subject/body. Guarded so a missing table
     * resolves to "no overrides" (all defaults).
     *
     * @return array{enabled: array<string, bool>, tx: array<string, array<string, array{subject: ?string, body: string}>>}
     */
    private function map(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
            if (! Schema::hasTable('mail_templates')) {
                return ['enabled' => [], 'tx' => []];
            }

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
