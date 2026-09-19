<?php

namespace App\Upgrade\Steps;

use App\Mail\Template\MailTemplate;
use App\Upgrade\Column;
use App\Upgrade\SourceLocale;
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
            'locale' => Column::expr(SourceLocale::foldExpr(), uses: ['lang']),
            'subject' => Column::source('title'),
            'body' => Column::source('template'),
        ];
    }

    public function filter(): ?string
    {
        return sprintf(
            '`id` IN (SELECT `id` FROM %s WHERE `name` IN (%s)) AND %s',
            SourceRef::table('notification_mail'),
            $this->sourceNameList(),
            self::templateCarriedExpr(),
        );
    }

    public function filterColumns(): array
    {
        return ['id', 'template'];
    }

    /**
     * OpenPNE 3's `NotificationMailTable::fetchTemplate()` reads a row only while `template` is truthy,
     * so '' and '0' are the values it ignores; compared as bytes because the source collation pads
     * spaces. Public so MailTemplatePreflight inspects the same rows the step carries.
     */
    public static function templateCarriedExpr(): string
    {
        return "CAST(`template` AS BINARY) NOT IN ('', '0')";
    }

    public function targetDefaults(): array
    {
        // `id` is OpenPNE 4's own surrogate key and created_at / updated_at have no OpenPNE 3 source,
        // so all three rely on the schema default.
        return ['id', 'created_at', 'updated_at'];
    }

    private function sourceNameList(): string
    {
        return implode(', ', array_map(
            static fn (MailTemplate $t): string => "'{$t->op3SourceName()}'",
            MailTemplate::importable(),
        ));
    }
}
