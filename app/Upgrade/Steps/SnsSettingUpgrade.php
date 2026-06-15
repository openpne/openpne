<?php

namespace App\Upgrade\Steps;

use App\Support\SnsSettingKey;
use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `sns_config` → OpenPNE 4 `sns_settings`, for the keys the typed SnsSettingKey registry
 * opts into (display settings + gadget layout). The migrated names and the name→key remap are both
 * derived from SnsSettingKey, so registering a migratable key is all it takes — no second list to drift.
 *
 * The security keys (registration mode, CAPTCHA) are excluded via isMigratedFromOp3() so an OpenPNE 3
 * value cannot silently override their fail-closed default; carrying those over is a separate,
 * security-reviewed decision. The migrated values are plain strings (layoutA, the SNS name, …) so
 * `value` copies verbatim.
 */
class SnsSettingUpgrade extends UpgradeStep
{
    protected string $source = 'sns_config';

    protected string $target = 'sns_settings';

    public function columns(): array
    {
        return [
            'key' => Column::expr($this->keyCase(), uses: ['name']),
            'value' => Column::source('value'),
        ];
    }

    public function filter(): ?string
    {
        return sprintf('`name` IN (%s)', $this->nameList());
    }

    public function filterColumns(): array
    {
        return ['name'];
    }

    public function gaps(): array
    {
        return [
            'id' => 'OpenPNE 3 sns_config surrogate key; sns_settings is keyed by the setting name (`key`), not a numeric id.',
        ];
    }

    /** @return list<SnsSettingKey> keys that opt into the OpenPNE 3 copy. */
    private function migratedKeys(): array
    {
        return array_values(array_filter(
            SnsSettingKey::cases(),
            static fn (SnsSettingKey $key): bool => $key->isMigratedFromOp3(),
        ));
    }

    private function nameList(): string
    {
        return implode(', ', array_map(
            static fn (SnsSettingKey $key): string => "'{$key->op3SourceName()}'",
            $this->migratedKeys(),
        ));
    }

    /** `sns_config.name` → the SnsSettingKey case value (the stored `key`), built from the registry. */
    private function keyCase(): string
    {
        $whens = array_map(
            static fn (SnsSettingKey $key): string => sprintf("WHEN '%s' THEN '%s'", $key->op3SourceName(), $key->value),
            $this->migratedKeys(),
        );

        return 'CASE `name` '.implode(' ', $whens).' END';
    }
}
