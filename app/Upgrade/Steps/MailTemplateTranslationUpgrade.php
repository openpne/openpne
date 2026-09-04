<?php

namespace App\Upgrade\Steps;

use App\Mail\Template\MailTemplate;
use App\Upgrade\Column;
use App\Upgrade\SourceRef;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `notification_mail_translation` → OpenPNE 4 `mail_template_translations`, keyed by the
 * notification_mail id (mail_template_id) and restricted to the templates MailTemplateUpgrade
 * imports. Subject and body copy verbatim so a migrated template renders byte-for-byte.
 */
class MailTemplateTranslationUpgrade extends UpgradeStep
{
    protected string $source = 'notification_mail_translation';

    protected string $target = 'mail_template_translations';

    public function columns(): array
    {
        return [
            'mail_template_id' => Column::source('id'),
            'locale' => Column::expr(self::localeExpr(), uses: ['lang']),
            'subject' => Column::source('title'),
            'body' => Column::source('template'),
        ];
    }

    public function filter(): ?string
    {
        return sprintf(
            '`id` IN (SELECT `id` FROM %s WHERE `name` IN (%s))',
            SourceRef::table('notification_mail'),
            $this->sourceNameList(),
        );
    }

    public function filterColumns(): array
    {
        return ['id'];
    }

    public function targetDefaults(): array
    {
        // `id` is OpenPNE 4's own surrogate key and created_at / updated_at have no OpenPNE 3 source,
        // so all three rely on the schema default.
        return ['id', 'created_at', 'updated_at'];
    }

    /**
     * OpenPNE 3 `lang` (ja_JP, en_US, …) folded to the locale slug, an unrecognised value kept verbatim
     * so it satisfies NOT NULL and stays inert rather than mislabelled. Public and static because
     * MailTemplatePreflight must fold by the same expression: LIKE runs under the source collation,
     * so a PHP fold would disagree on inputs like `JA_JP`.
     */
    public static function localeExpr(): string
    {
        return "CASE WHEN `lang` LIKE 'ja%' THEN 'ja' WHEN `lang` LIKE 'en%' THEN 'en' ELSE `lang` END";
    }

    private function sourceNameList(): string
    {
        return implode(', ', array_map(
            static fn (MailTemplate $t): string => "'{$t->op3SourceName()}'",
            MailTemplate::importable(),
        ));
    }
}
