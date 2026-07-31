<?php

namespace App\Upgrade\Steps;

use App\Support\SnsSettingKey;
use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * Shared shape for the steps carrying OpenPNE 3's feature availability into `sns_settings`
 * (App\Support\Feature, docs/internals/feature-toggles.md).
 *
 * Only a switched-off unit gets a row: an absent row means enabled on both sides — OpenPNE 3's lazy
 * `plugin` rows — so the filter keeps only the source rows that switched something off and `value` is
 * always the literal '0'. A site that never disabled anything migrates with no feature row at all.
 */
abstract class FeatureFlagUpgrade extends UpgradeStep
{
    protected string $target = 'sns_settings';

    public function columns(): array
    {
        return [
            'key' => $this->keyColumn(),
            'value' => Column::expr("'0'"),
        ];
    }

    /**
     * The rows this step owns in the shared target: its own keys, switched off. The '0' scope also
     * keeps the admin Features page out of the count — its first save materializes every key,
     * enabled ones included — and verify is the pre-switchover gate, so an operator disabling
     * something between the run and the check is outside its window.
     */
    public function targetFilter(): ?string
    {
        $keys = implode(', ', array_map(
            static fn (SnsSettingKey $key): string => "'{$key->value}'",
            $this->featureKeys(),
        ));

        return "`key` IN ({$keys}) AND `value` = '0'";
    }

    /** The `sns_settings.key` a matched source row becomes. */
    abstract protected function keyColumn(): Column;

    /** @return list<SnsSettingKey> every key this step can write */
    abstract protected function featureKeys(): array;
}
