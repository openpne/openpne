<?php

namespace App\Upgrade\Runner;

use App\Mail\Template\MailTemplate;
use App\Mail\Template\MailTemplateFault;
use App\Mail\Template\MailTemplateRenderer;
use App\Mail\Template\MailTemplateService;
use App\Mail\Template\UnsupportedMailTemplateSyntaxException;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Steps\MailTemplateTranslationUpgrade;
use App\Upgrade\Steps\MailTemplateUpgrade;
use Illuminate\Support\Facades\DB;

/**
 * Render-tests the OpenPNE 3 mail templates the translation step will copy unparsed, so an operator
 * sees a template OpenPNE 4 cannot render before the cutover (docs/internals/upgrade.md, "Source
 * preflight"). Locales are folded by the step's own expression: LIKE under the source collation
 * folds `JA_JP` to `ja`, where a PHP fold would not.
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
                // Never read (OpenPNE 4 folds the recipient locale to ja/en before the lookup) and not
                // render-tested, since TermService has no terms for it and every `op_term.*` reference
                // would look like a missing variable.
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
     * The source rows the import carries, id => the template they become, resolved by the step's own
     * filter and key CASE. A PHP name comparison would cover a different row set: the source collation
     * is case-insensitive and PAD SPACE.
     *
     * @return array<int, MailTemplate>
     */
    private function importableTemplates(string $prefix, ?string $database): array
    {
        $step = new MailTemplateUpgrade;

        $rows = DB::select(
            'select `id`, '.MailTemplateUpgrade::keyCase().' as `template_key` from '
            .InsertSelectCompiler::qualify($database, $prefix, 'notification_mail')
            .' where '.$step->filter(),
        );

        $templates = [];
        foreach ($rows as $row) {
            $template = MailTemplate::tryFrom((string) $row->template_key);
            if ($template !== null) {
                $templates[(int) $row->id] = $template;
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
