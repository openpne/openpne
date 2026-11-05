<?php

namespace App\Upgrade\Runner;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceRef;
use App\Upgrade\Steps\DirectMessageUpgrade;
use Illuminate\Support\Facades\DB;

/**
 * Counts the `message` rows the mailbox steps leave behind, per message type, so their drop is not
 * silent. It runs before the absent optional tables are materialised, so a source without
 * opMessagePlugin is not queried.
 */
final class MessageTypePreflight
{
    private const SAMPLE = 5;

    /** The notification letters opMessagePlugin writes (its stock types and the invitation types of its later builds). */
    public const NOTIFICATION_TYPES = [
        'friend_link', 'community_joining_request', 'community_taking_over', 'community_sub_admin_request',
        'invite_community', 'invite_event',
    ];

    public const W_NOTIFICATION_TYPE = 'W-MESSAGE-NOTIFICATION-TYPE';

    public const W_UNKNOWN_TYPE = 'W-MESSAGE-UNKNOWN-TYPE';

    private string $prefix = '';

    private ?string $database = null;

    /**
     * @param  list<string>  $readTables  source tables present and read by the run; `message` absent means nothing to count
     */
    public function inspect(string $sourcePrefix, ?string $sourceDatabase, array $readTables): PreflightReport
    {
        if (! in_array('message', $readTables, true) || ! in_array('message_type', $readTables, true)) {
            return new PreflightReport([], []);
        }
        $this->prefix = $sourcePrefix;
        $this->database = $sourceDatabase;

        $warnings = [];
        foreach ($this->grouped() as [$type, $rows, $ids]) {
            $warnings[] = in_array($type, self::NOTIFICATION_TYPES, true)
                ? new Warning(self::W_NOTIFICATION_TYPE, self::notificationTypeMessage($type, $rows, $ids), $rows)
                : new Warning(self::W_UNKNOWN_TYPE, self::unknownTypeMessage($type, $rows, $ids), $rows);
        }

        return new PreflightReport([], $warnings);
    }

    /** @param  list<int>  $ids */
    public static function notificationTypeMessage(string $type, int $rows, array $ids): string
    {
        return "source `message` has {$rows} `{$type}` row(s) (e.g. ids ".implode(', ', $ids).') — OpenPNE 3 notification letters, not migrated; what they announced is carried by the member and community tables.';
    }

    /** @param  list<int>  $ids */
    public static function unknownTypeMessage(string $type, int $rows, array $ids): string
    {
        $label = $type === '' ? 'no message type' : "message type `{$type}`";

        return "source `message` has {$rows} row(s) with {$label} (e.g. ids ".implode(', ', $ids).') — not a type opMessagePlugin writes: a third-party plugin or a source customisation. Not migrated.';
    }

    /**
     * Grouped on the bytes, not the column: the source's collation may fold two names into one.
     *
     * @return list<array{string, int, list<int>}> [type_name ('' when the type row is missing), rows, first ids]
     */
    private function grouped(): array
    {
        $types = implode(', ', array_map(static fn (string $type): string => "'{$type}'", DirectMessageUpgrade::MIGRATED_TYPES));
        $type = 'CAST(COALESCE(`mt`.`type_name`, \'\') AS BINARY)';
        $from = 'FROM '.SourceRef::table('message').' AS `m` LEFT JOIN '.SourceRef::table('message_type').' AS `mt` ON `mt`.`id` = `m`.`message_type_id`'
            ." WHERE (`mt`.`type_name` IS NULL OR `mt`.`type_name` NOT IN ({$types}))";
        $result = [];
        foreach (DB::select($this->resolve("SELECT {$type} AS `type`, COUNT(*) AS `rows` {$from} GROUP BY {$type} ORDER BY {$type}")) as $group) {
            $ids = array_map(
                static fn (object $r): int => (int) $r->id,
                DB::select($this->resolve("SELECT `m`.`id` AS `id` {$from} AND {$type} = CAST(? AS BINARY) ORDER BY `m`.`id` LIMIT ".self::SAMPLE), [$group->type]),
            );
            $result[] = [(string) $group->type, (int) $group->rows, $ids];
        }

        return $result;
    }

    private function resolve(string $sql): string
    {
        return (new InsertSelectCompiler)->resolveSourceRefs($sql, $this->prefix, $this->database);
    }
}
