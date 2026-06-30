<?php

namespace App\Upgrade\Steps;

use App\Mail\Template\MailTemplate;
use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `notification_mail` → OpenPNE 4 `mail_templates`, for the templates the MailTemplate registry
 * imports (those with an OpenPNE 3 source name). The migrated names and the name→key remap are both
 * derived from MailTemplate::importable(), so giving a case an import origin is all it takes — no second
 * list to drift. `id` is carried verbatim so MailTemplateTranslationUpgrade can FK the per-locale wording.
 *
 * is_enabled is honored only for configurable templates; required/security mails (registration, email
 * change) are forced on so an OpenPNE 3 `is_enabled=0` can never break those flows after the upgrade.
 */
class MailTemplateUpgrade extends UpgradeStep
{
    protected string $source = 'notification_mail';

    protected string $target = 'mail_templates';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'key' => Column::expr($this->keyCase(), uses: ['name']),
            'is_enabled' => Column::expr($this->isEnabledExpr(), uses: ['name', 'is_enabled']),
        ];
    }

    public function filter(): ?string
    {
        return sprintf('`name` IN (%s)', $this->sourceNameList(MailTemplate::importable()));
    }

    public function filterColumns(): array
    {
        return ['name'];
    }

    public function targetDefaults(): array
    {
        // OpenPNE 3 `notification_mail` has no created_at / updated_at; the nullable columns rely on their default.
        return ['created_at', 'updated_at'];
    }

    public function gaps(): array
    {
        return [
            'renderer' => 'OpenPNE 3 per-row template renderer (always "twig"). OpenPNE 4 renders every template with its own sandboxed Twig, so there is no per-row renderer to carry.',
        ];
    }

    /** `notification_mail.name` → the MailTemplate case value (the stored `key`), built from the registry. */
    private function keyCase(): string
    {
        $whens = array_map(
            static fn (MailTemplate $t): string => sprintf("WHEN '%s' THEN '%s'", $t->op3SourceName(), $t->value),
            MailTemplate::importable(),
        );

        return 'CASE `name` '.implode(' ', $whens).' END';
    }

    /** Source is_enabled for configurable templates; forced on (1) for the required/security mails. */
    private function isEnabledExpr(): string
    {
        $configurable = array_values(array_filter(
            MailTemplate::importable(),
            static fn (MailTemplate $t): bool => $t->isConfigurable(),
        ));

        if ($configurable === []) {
            return '1';
        }

        return sprintf('CASE WHEN `name` IN (%s) THEN `is_enabled` ELSE 1 END', $this->sourceNameList($configurable));
    }

    /** @param list<MailTemplate> $templates */
    private function sourceNameList(array $templates): string
    {
        return implode(', ', array_map(
            static fn (MailTemplate $t): string => "'{$t->op3SourceName()}'",
            $templates,
        ));
    }
}
