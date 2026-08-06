<?php

namespace App\Upgrade\Runner;

use App\Mail\Template\MailTemplate;
use App\Mail\Template\MailTemplateFault;
use App\Mail\Template\MailTemplateRenderer;
use App\Mail\Template\MailTemplateService;
use App\Mail\Template\UnsupportedMailTemplateSyntaxException;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Steps\MailTemplateTranslationUpgrade;
use Illuminate\Support\Facades\DB;

/**
 * Render-tests the OpenPNE 3 mail templates before the upgrade copies them.
 *
 * The translation step is an INSERT...SELECT, so a body reaches OpenPNE 4 without ever being parsed: an
 * admin customisation using a construct outside the supported dialect, or an `app_url_for` route with no
 * OpenPNE 4 mapping, stays invisible until the first send after the cutover fails. This walks the source
 * rows the step will carry and renders each one the way the application will, so the operator sees the
 * breakage while the OpenPNE 3 site is still serving.
 *
 * Two passes per row, because the faults are not distinguishable from one exception type (see
 * MailTemplateFault): a lenient render reproduces production and reports what will actually throw, then a
 * strict render of what survived reports a referenced-but-absent variable — the only difference
 * strict_variables makes.
 *
 * Locales are folded by the step's own expression rather than re-folded in PHP: LIKE runs under the
 * source collation, so `JA_JP` folds to `ja` in the INSERT while str_starts_with would disagree. Every
 * verdict here — collision, column width, inert, renderable — is keyed off that same folded value.
 */
final class MailTemplatePreflight
{
    /** The `locale` column mail_template_translations declares; a wider folded value cannot be inserted. */
    private const LOCALE_COLUMN_LENGTH = 5;

    /** The locales MailTemplateService resolves a recipient to; a row folded to anything else is never read. */
    private const READ_LOCALES = ['ja', 'en'];

    public function inspect(string $sourcePrefix, ?string $sourceDatabase): MailTemplatePreflightReport
    {
        $templates = $this->importableTemplates($sourcePrefix, $sourceDatabase);

        if ($templates === []) {
            return new MailTemplatePreflightReport([], []);
        }

        $errors = [];
        $warnings = [];
        $foldedByTemplate = [];

        foreach ($this->translations($sourcePrefix, $sourceDatabase) as $row) {
            $template = $templates[(int) $row->mail_template_id] ?? null;
            if ($template === null) {
                continue; // a translation whose template row the name filter excludes; the step skips it too
            }

            $name = (string) $template->op3SourceName();
            $folded = (string) $row->folded_locale;
            $foldedByTemplate[$name][$folded][] = (string) $row->lang;

            if (mb_strlen($folded) > self::LOCALE_COLUMN_LENGTH) {
                $errors[] = self::localeTooLongMessage($name, (string) $row->lang, $folded);

                continue;
            }

            if (! in_array($folded, self::READ_LOCALES, true)) {
                // Inert: OpenPNE 4 folds the RECIPIENT locale to ja/en before looking a row up, so this one
                // is never read. Rendering it would also misfire — TermService has no terms for it, so every
                // `op_term.*` reference would look like a missing variable.
                $warnings[] = self::inertLocaleMessage($name, (string) $row->lang, $folded);

                continue;
            }

            $fault = $this->faultOf($template, $folded, (string) $row->title, (string) $row->template);

            if ($fault !== null) {
                $warnings[] = self::unrenderableMessage($name, $folded, ...$fault);
            }
        }

        foreach ($foldedByTemplate as $name => $langsByLocale) {
            foreach ($langsByLocale as $locale => $langs) {
                if (count($langs) > 1) {
                    $errors[] = self::localeCollisionMessage($name, (string) $locale, $langs);
                }
            }
        }

        return new MailTemplatePreflightReport($errors, $warnings);
    }

    /**
     * The first fault in a row's subject and body, or null when both render. Subject first: it is rendered
     * first at send time too, so the operator sees the same failure the mail would hit.
     *
     * @return array{MailTemplateFault, string}|null the fault and the engine's message
     */
    private function faultOf(MailTemplate $template, string $locale, string $subject, string $body): ?array
    {
        $lenient = new MailTemplateService(new MailTemplateRenderer);

        try {
            if ($subject !== '') {
                $lenient->assertSubjectRenderable($template, $locale, $subject);
            }
            $lenient->assertBodyRenderable($template, $locale, $body);
        } catch (UnsupportedMailTemplateSyntaxException $e) {
            return [$e->fault, $e->getMessage()];
        }

        // It renders in production; anything strict_variables now rejects is a variable or key the
        // template references and OpenPNE 4 does not supply — which production would render as empty.
        $strict = new MailTemplateService(new MailTemplateRenderer(strictVariables: true));

        try {
            if ($subject !== '') {
                $strict->assertSubjectRenderable($template, $locale, $subject);
            }
            $strict->assertBodyRenderable($template, $locale, $body);
        } catch (UnsupportedMailTemplateSyntaxException $e) {
            return [MailTemplateFault::MissingContext, $e->getMessage()];
        }

        return null;
    }

    /**
     * The source rows the import carries, id => the template they become. Same registry-derived filter as
     * MailTemplateUpgrade, so a name the step drops is not render-tested here either.
     *
     * @return array<int, MailTemplate>
     */
    private function importableTemplates(string $prefix, ?string $database): array
    {
        $byName = [];
        foreach (MailTemplate::importable() as $template) {
            $byName[(string) $template->op3SourceName()] = $template;
        }

        $rows = DB::select(
            'select `id`, `name` from '.InsertSelectCompiler::qualify($database, $prefix, 'notification_mail')
            .' where `name` in ('.implode(', ', array_fill(0, count($byName), '?')).')',
            array_keys($byName),
        );

        $templates = [];
        foreach ($rows as $row) {
            // The IN comparison runs under the source collation, so a differently-cased name can come
            // back; resolve it the same way (the step's own filter carries that row too).
            foreach ($byName as $name => $template) {
                if (strcasecmp($name, (string) $row->name) === 0) {
                    $templates[(int) $row->id] = $template;
                }
            }
        }

        return $templates;
    }

    /**
     * The per-locale wording, with the locale the step will write. Ordered so the listing is stable
     * across runs rather than following storage order.
     *
     * @return list<object>
     */
    private function translations(string $prefix, ?string $database): array
    {
        return DB::select(
            'select `id` as `mail_template_id`, `lang`, '.MailTemplateTranslationUpgrade::localeExpr().' as `folded_locale`,'
            .' `title`, `template` from '.InsertSelectCompiler::qualify($database, $prefix, 'notification_mail_translation')
            .' order by `id` asc, `lang` asc',
        );
    }

    public static function inertLocaleMessage(string $name, string $lang, string $locale): string
    {
        return "mail template `{$name}` has a `{$lang}` translation, which migrates as locale `{$locale}` — OpenPNE 4 only ever"
            .' reads ja and en, so the row is carried but never sent. Re-tag it in the source if it was meant to be one of those.';
    }

    public static function unrenderableMessage(string $name, string $locale, MailTemplateFault $fault, string $detail): string
    {
        $consequence = $fault->blocksSending()
            ? 'This mail cannot be sent until the template is fixed — resolve it before the cutover, in the source or in the OpenPNE 4 mail template editor.'
            : 'The mail still sends, rendering that reference as empty text.';

        return "mail template `{$name}` ({$locale}) does not render — {$fault->label()}: {$detail} The row migrates as-is. {$consequence}";
    }

    /** @param list<string> $langs */
    public static function localeCollisionMessage(string $name, string $locale, array $langs): string
    {
        return "mail template `{$name}` has ".count($langs).' translations that all migrate as locale `'.$locale.'` ('
            .implode(', ', $langs).') — mail_template_translations allows one row per template and locale, so the step'
            .' would fail. Keep a single translation per language in the source.';
    }

    public static function localeTooLongMessage(string $name, string $lang, string $locale): string
    {
        return "mail template `{$name}` has a `{$lang}` translation, which migrates as locale `{$locale}` — longer than the "
            .self::LOCALE_COLUMN_LENGTH.'-character `mail_template_translations`.`locale` column, so the step would fail.'
            .' Re-tag it in the source as ja or en, or drop the row.';
    }
}
