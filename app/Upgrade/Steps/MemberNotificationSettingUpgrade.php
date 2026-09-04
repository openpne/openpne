<?php

namespace App\Upgrade\Steps;

use App\Notifications\Settings\NotificationChannel;
use App\Notifications\Settings\NotificationKind;
use App\Upgrade\Column;
use App\Upgrade\SourceRef;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `member_config` opt-in rows → OpenPNE 4 `member_notification_settings`, for every
 * NotificationKind × NotificationChannel source name; an unwired kind imports too, since the upgrade
 * is one-shot and its stored choice must survive. '0' is the only opt-out (the source's fail-open
 * default); the filter keeps the latest row per (member_id, name), as member_config has no such
 * unique.
 */
class MemberNotificationSettingUpgrade extends UpgradeStep
{
    protected string $source = 'member_config';

    protected string $target = 'member_notification_settings';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'member_id' => Column::source('member_id'),
            'kind' => Column::expr($this->kindCase(), uses: ['name']),
            'channel' => Column::expr($this->channelCase(), uses: ['name']),
            'is_enabled' => Column::expr("CASE `value` WHEN '0' THEN 0 ELSE 1 END", uses: ['value']),
            'created_at' => Column::source('created_at'),
            'updated_at' => Column::source('updated_at'),
        ];
    }

    public function filter(): ?string
    {
        $names = implode(', ', array_map(
            static fn (array $pair): string => "'{$pair[0]->op3ConfigName($pair[1])}'",
            $this->pairs(),
        ));

        // Latest row per (member_id, name): collapse any KV duplicates to the most recently
        // written one so the (member_id, kind, channel) unique target never sees two rows.
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
            'value_datetime' => 'OpenPNE 3 datetime-typed config value; the migrated settings are boolean flags stored in `is_enabled`.',
            'name_value_hash' => 'OpenPNE 3 search hash for unique-config lookups; the typed settings store does not need it.',
        ];
    }

    /** `member_config.name` → the NotificationKind case value, built from the registry. */
    private function kindCase(): string
    {
        $whens = array_map(
            static fn (array $pair): string => sprintf("WHEN '%s' THEN '%s'", $pair[0]->op3ConfigName($pair[1]), $pair[0]->value),
            $this->pairs(),
        );

        return 'CASE `name` '.implode(' ', $whens).' END';
    }

    /** `member_config.name` → the NotificationChannel case value, built from the registry. */
    private function channelCase(): string
    {
        $whens = array_map(
            static fn (array $pair): string => sprintf("WHEN '%s' THEN '%s'", $pair[0]->op3ConfigName($pair[1]), $pair[1]->value),
            $this->pairs(),
        );

        return 'CASE `name` '.implode(' ', $whens).' END';
    }

    /** @return list<array{0: NotificationKind, 1: NotificationChannel}> every (kind, channel) source key */
    private function pairs(): array
    {
        $pairs = [];
        foreach (NotificationKind::importableCases() as $kind) {
            foreach (NotificationChannel::cases() as $channel) {
                $pairs[] = [$kind, $channel];
            }
        }

        return $pairs;
    }
}
