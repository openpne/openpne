<?php

namespace App\Upgrade\Steps;

use App\Support\SnsSettingKey;
use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `sns_config` → OpenPNE 4 `sns_settings`, for the keys SnsSettingKey::isMigratedFromOp3()
 * opts in (the filter and the name → key CASE derive from the registry). The security keys
 * (registration mode, CAPTCHA) are excluded so an OpenPNE 3 value cannot override their fail-closed
 * default.
 */
class SnsSettingUpgrade extends UpgradeStep
{
    protected string $source = 'sns_config';

    protected string $target = 'sns_settings';

    public function columns(): array
    {
        return [
            'key' => Column::expr($this->keyCase(), uses: ['name']),
            'value' => $this->valueCase() === null ? Column::source('value') : Column::expr($this->valueCase(), uses: ['name', 'value']),
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

    /**
     * The rows this step owns in a target it shares with the feature-flag steps. surface_mode falls
     * outside by construction: it is not migrated, the runner stamps it after the walk.
     */
    public function targetFilter(): ?string
    {
        return sprintf('`key` IN (%s)', implode(', ', array_map(
            static fn (SnsSettingKey $key): string => "'{$key->value}'",
            $this->migratedKeys(),
        )));
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

    /** The stored value, rewritten only for a key with an op3ValueMap(); null when no migrated key has one. */
    private function valueCase(): ?string
    {
        $whens = [];
        foreach ($this->migratedKeys() as $key) {
            $map = $key->op3ValueMap();
            if ($map === null) {
                continue;
            }
            $inner = implode(' ', array_map(
                static fn (string $from, string $to): string => sprintf("WHEN '%s' THEN '%s'", $from, $to),
                array_keys($map),
                $map,
            ));
            $whens[] = sprintf("WHEN '%s' THEN CASE `value` %s ELSE `value` END", $key->op3SourceName(), $inner);
        }

        return $whens === [] ? null : 'CASE `name` '.implode(' ', $whens).' ELSE `value` END';
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
