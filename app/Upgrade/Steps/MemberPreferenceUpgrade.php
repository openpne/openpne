<?php

namespace App\Upgrade\Steps;

use App\Support\PreferenceKey;
use App\Support\Visibility;
use App\Upgrade\Column;
use App\Upgrade\SourceRef;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `member_config` rows → OpenPNE 4 `member_preferences`, for the names
 * PreferenceKey::upgradableCases() derive, latest row per (member_id, name) since member_config has
 * no such unique. Every PreferenceKey value is on OpenPNE 3's public_flag scale (SNS=1, friend=2,
 * private=3, web=4) with no is_open companion, so one value CASE serves all keys.
 */
class MemberPreferenceUpgrade extends UpgradeStep
{
    protected string $source = 'member_config';

    protected string $target = 'member_preferences';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'member_id' => Column::source('member_id'),
            'key' => Column::expr($this->keyCase(), uses: ['name']),
            'value' => Column::expr($this->visibilityValueCase(), uses: ['value']),
            'created_at' => Column::source('created_at'),
            'updated_at' => Column::source('updated_at'),
        ];
    }

    public function filter(): ?string
    {
        $names = implode(', ', array_map(
            static fn (PreferenceKey $key): string => "'{$key->op3SourceName()}'",
            PreferenceKey::upgradableCases(),
        ));

        // Latest row per (member_id, name): collapse any KV duplicates to the most recently
        // written one so the (member_id, key) unique target never sees two rows.
        return "`name` IN ({$names})"
            .' AND `id` = (SELECT MAX(`m2`.`id`) FROM '.SourceRef::table('member_config').' `m2`'
            .' WHERE `m2`.`member_id` = `member_config`.`member_id` AND `m2`.`name` = `member_config`.`name`)';
    }

    public function filterColumns(): array
    {
        return ['name', 'id', 'member_id'];
    }

    public function memberRefs(): array
    {
        return ['member_id'];
    }

    public function gaps(): array
    {
        return [
            'value_datetime' => 'OpenPNE 3 datetime-typed config value; the migrated preferences are integer visibility flags stored in `value`.',
            'name_value_hash' => 'OpenPNE 3 search hash for unique-config lookups; the typed preference store does not need it.',
        ];
    }

    /** `member_config.name` → the PreferenceKey case value, built from the registry. */
    private function keyCase(): string
    {
        $whens = array_map(
            static fn (PreferenceKey $key): string => sprintf("WHEN '%s' THEN '%s'", $key->op3SourceName(), $key->value),
            PreferenceKey::upgradableCases(),
        );

        return 'CASE `name` '.implode(' ', $whens).' END';
    }

    /**
     * OpenPNE 3 public_flag → the Visibility int (as a string, the codec's stored form). Each
     * branch reads the runtime enum so it cannot drift; an unexpected flag falls to Members.
     */
    private function visibilityValueCase(): string
    {
        return sprintf(
            "CASE `value` WHEN '4' THEN '%d' WHEN '2' THEN '%d' WHEN '3' THEN '%d' ELSE '%d' END",
            Visibility::Open->value,
            Visibility::Friends->value,
            Visibility::Private->value,
            Visibility::Members->value,
        );
    }
}
