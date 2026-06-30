<?php

namespace App\Upgrade\Steps;

use App\Mail\Template\MailTemplate;
use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `notification_mail_translation` → OpenPNE 4 `mail_template_translations` (the per-locale
 * subject/body). OpenPNE 3 keys the translation by (id, lang) where id is the notification_mail id, so it
 * maps onto mail_template_id; OpenPNE 4 adds its own surrogate id. The body/subject copy verbatim so a
 * migrated template renders byte-for-byte. Restricted to the templates MailTemplateUpgrade imports, so a
 * translation never references a mail_templates row that was filtered out.
 */
class MailTemplateTranslationUpgrade extends UpgradeStep
{
    protected string $source = 'notification_mail_translation';

    protected string $target = 'mail_template_translations';

    public function columns(): array
    {
        return [
            'mail_template_id' => Column::source('id'),
            'locale' => Column::expr($this->localeExpr(), uses: ['lang']),
            'subject' => Column::source('title'),
            'body' => Column::source('template'),
        ];
    }

    public function filter(): ?string
    {
        // The sibling `notification_mail` is named unqualified (like NavigationTranslationUpgrade's
        // navigation subquery): correct for the fleet (no source prefix, same database).
        return sprintf(
            '`id` IN (SELECT `id` FROM `notification_mail` WHERE `name` IN (%s))',
            $this->sourceNameList(),
        );
    }

    public function filterColumns(): array
    {
        return ['id'];
    }

    public function targetDefaults(): array
    {
        // `id` is OpenPNE 4's own surrogate key (auto-increment); created_at / updated_at have no OpenPNE 3
        // source. All three rely on their schema default.
        return ['id', 'created_at', 'updated_at'];
    }

    /**
     * OpenPNE 3 `lang` (ja_JP, en_US, …) folded to the OpenPNE 4 locale slug, matching MemberUpgrade. An
     * unrecognised lang is kept verbatim, not mis-folded into ja/en: it satisfies the NOT NULL column and
     * stays inert (MailTemplateService only ever looks up ja/en), rather than silently mislabelling a row.
     */
    private function localeExpr(): string
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
