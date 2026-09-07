<?php

namespace App\Upgrade\Runner;

use App\Support\Visibility;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceRef;
use App\Upgrade\Steps\ActivityThread;
use Illuminate\Support\Facades\DB;

/**
 * Counts, before the first write, every activity row the routing rules (ActivityThread) drop,
 * re-parent or refuse, so no disposition is silent and a row a step would fail on stops the run
 * here (docs/internals/upgrade.md, "Source preflight"). MySQL only, like the steps it describes.
 */
final class ActivityPreflight
{
    /** The template names ActivityTemplateRenderer renders; any other keeps its stored body. */
    public const KNOWN_TEMPLATES = ['diary', 'community_topic', 'community_event'];

    /** Ids shown per finding; the runbook's SQL lists the rest. */
    private const SAMPLE = 5;

    /** The slot column of both image join tables is unsignedTinyInteger. */
    private const MAX_IMAGES = 255;

    private string $prefix = '';

    private ?string $database = null;

    public function inspect(string $sourcePrefix, ?string $sourceDatabase): ActivityPreflightReport
    {
        $this->prefix = $sourcePrefix;
        $this->database = $sourceDatabase;

        $a = 'activity_data';
        $errors = [];
        $warnings = [];

        $flags = implode(', ', array_map(static fn (Visibility $v): int => $v->value, Visibility::cases()));
        [$rows, $ids] = $this->rows(ActivityThread::startsThread($a)." AND `{$a}`.`public_flag` NOT IN ({$flags})");
        if ($rows > 0) {
            $errors[] = self::unknownPublicFlagMessage($rows, $ids);
        }

        $crowded = DB::select($this->resolve(
            'SELECT `activity_data_id` AS `id`, COUNT(*) AS `images` FROM '.SourceRef::table('activity_image')
            .' WHERE `file_id` IS NOT NULL GROUP BY `activity_data_id` HAVING `images` > '.self::MAX_IMAGES.' ORDER BY `activity_data_id`',
        ));
        if ($crowded !== []) {
            $errors[] = self::tooManyImagesMessage(count($crowded), array_slice(array_map(static fn (object $r): int => (int) $r->id, $crowded), 0, self::SAMPLE));
        }

        $isReply = "`{$a}`.`in_reply_to_activity_id` IS NOT NULL AND ".ActivityThread::parentExists($a);
        $parentIsReply = 'EXISTS (SELECT 1 FROM '.SourceRef::table('activity_data')." AS `parent_of` WHERE `parent_of`.`id` = `{$a}`.`in_reply_to_activity_id` AND NOT ".ActivityThread::startsThread('parent_of').')';
        $rootId = ActivityThread::rootId($a);

        [$rows, $ids] = $this->rows("{$isReply} AND {$parentIsReply} AND {$rootId} IS NOT NULL");
        if ($rows > 0) {
            $warnings[] = self::deepReplyMessage($rows, $ids);
        }

        [$rows, $ids] = $this->rows("{$isReply} AND {$rootId} IS NULL");
        if ($rows > 0) {
            $warnings[] = self::unresolvableReplyMessage($rows, $ids);
        }

        [$rows, $ids] = $this->rows(ActivityThread::isDangling($a));
        if ($rows > 0) {
            $warnings[] = self::danglingReplyMessage($rows, $ids);
        }

        foreach ($this->grouped("`{$a}`.`foreign_table`", ActivityThread::startsThread($a)." AND `{$a}`.`foreign_table` IS NOT NULL AND `{$a}`.`foreign_table` <> 'community'") as $value => [$rows, $ids]) {
            $warnings[] = self::otherScopeMessage((string) $value, $rows, $ids);
        }

        $communityRoot = ActivityThread::startsThread($a)." AND `{$a}`.`foreign_table` = 'community'";
        $groupExists = 'EXISTS (SELECT 1 FROM '.SourceRef::table('community')." AS `thread_group` WHERE `thread_group`.`id` = `{$a}`.`foreign_id`)";

        [$rows, $ids] = $this->rows("{$communityRoot} AND NOT {$groupExists}");
        if ($rows > 0) {
            $warnings[] = self::orphanGroupThreadMessage($rows, $ids);
        }

        foreach ($this->grouped("`{$a}`.`public_flag`", "{$communityRoot} AND {$groupExists} AND `{$a}`.`public_flag` <> ".ActivityThread::MEMBERS_FLAG) as $flag => [$rows, $ids]) {
            $warnings[] = self::nonMembersGroupThreadMessage((int) $flag, $rows, $ids);
        }

        // NULL-safe on both columns: the fleet's shape is a community root with a scope-less reply.
        [$rows, $ids] = $this->rows("{$isReply} AND {$rootId} IS NOT NULL AND NOT (`{$a}`.`foreign_table` <=> "
            .ActivityThread::rootColumn($a, 'foreign_table')." AND `{$a}`.`foreign_id` <=> ".ActivityThread::rootColumn($a, 'foreign_id').')');
        if ($rows > 0) {
            $warnings[] = self::crossScopeReplyMessage($rows, $ids);
        }

        $uriOnly = DB::select($this->resolve('SELECT `id` FROM '.SourceRef::table('activity_image').' WHERE `file_id` IS NULL ORDER BY `id`'));
        if ($uriOnly !== []) {
            $warnings[] = self::uriOnlyImageMessage(count($uriOnly), array_slice(array_map(static fn (object $r): int => (int) $r->id, $uriOnly), 0, self::SAMPLE));
        }

        $landing = 'CASE WHEN '.ActivityThread::landsOnTimeline($a)." THEN 'timeline' WHEN ".ActivityThread::landsInGroup($a)." THEN 'group' ELSE 'none' END";
        foreach (DB::select($this->resolve(
            "SELECT `{$a}`.`template` AS `template`, {$landing} AS `landing`, COUNT(*) AS `rows` FROM ".SourceRef::table('activity_data')." AS `{$a}`"
            ." WHERE `{$a}`.`template` IS NOT NULL GROUP BY `template`, `landing` ORDER BY `template`, `landing`",
        )) as $row) {
            $warnings[] = self::templateMessage((string) $row->template, (string) $row->landing, (int) $row->rows);
        }

        return new ActivityPreflightReport($errors, $warnings);
    }

    /** @param  list<int>  $ids */
    public static function unknownPublicFlagMessage(int $rows, array $ids): string
    {
        return "source `activity_data` has {$rows} thread-starting row(s) whose public_flag is not 0..3 (e.g. ids ".self::list($ids).') — no OpenPNE 4 audience answers to it, and the timeline step would fail on the row mid-run. Set the flag in the source, then re-run.';
    }

    /** @param  list<int>  $ids */
    public static function tooManyImagesMessage(int $activities, array $ids): string
    {
        return 'source `activity_image` attaches more than '.self::MAX_IMAGES." files to {$activities} activity(ies) (e.g. ids ".self::list($ids).') — the OpenPNE 4 slot column holds 255, so the image step would fail mid-run. Remove the surplus rows in the source, then re-run.';
    }

    /** @param  list<int>  $ids */
    public static function deepReplyMessage(int $rows, array $ids): string
    {
        return "source `activity_data` has {$rows} reply(ies) answering another reply (e.g. ids ".self::list($ids).') — OpenPNE 4 threads are flat, so each is attached to its thread root instead (the OpenPNE 3 timeline showed it under the root too).';
    }

    /** @param  list<int>  $ids */
    public static function unresolvableReplyMessage(int $rows, array $ids): string
    {
        return "source `activity_data` has {$rows} reply(ies) nested more than ".ActivityThread::MAX_DEPTH.' deep (e.g. ids '.self::list($ids).') — not migrated. Point in_reply_to_activity_id at the root in the source to carry them.';
    }

    /** @param  list<int>  $ids */
    public static function danglingReplyMessage(int $rows, array $ids): string
    {
        return "source `activity_data` has {$rows} reply(ies) whose parent row is missing (e.g. ids ".self::list($ids).') — OpenPNE 3 deletes replies with their parent, so this is an incomplete dump. Each is migrated as a post of its own, with no lineage.';
    }

    /** @param  list<int>  $ids */
    public static function otherScopeMessage(string $foreignTable, int $threads, array $ids): string
    {
        return "source `activity_data` has {$threads} thread(s) scoped to `{$foreignTable}` (e.g. ids ".self::list($ids).') — OpenPNE 4 lands only unscoped threads (the timeline) and community threads (group talk), so these and their replies are not migrated.';
    }

    /** @param  list<int>  $ids */
    public static function orphanGroupThreadMessage(int $threads, array $ids): string
    {
        return "source `activity_data` has {$threads} community thread(s) whose community no longer exists (e.g. ids ".self::list($ids).') — not migrated, with their replies and images: OpenPNE 4 deletes a talk with its group, and OpenPNE 3 showed these to nobody.';
    }

    /** @param  list<int>  $ids */
    public static function nonMembersGroupThreadMessage(int $publicFlag, int $threads, array $ids): string
    {
        return "source `activity_data` has {$threads} community thread(s) with public_flag {$publicFlag} (e.g. ids ".self::list($ids).') — group talk has no per-message audience, so only a thread posted to every member (public_flag 1) lands; these and their replies are not migrated.';
    }

    /** @param  list<int>  $ids */
    public static function crossScopeReplyMessage(int $rows, array $ids): string
    {
        return "source `activity_data` has {$rows} reply(ies) whose community scope differs from their thread root's (e.g. ids ".self::list($ids).") — each lands where its root does; the reply's own scope is ignored.";
    }

    /** @param  list<int>  $ids */
    public static function uriOnlyImageMessage(int $rows, array $ids): string
    {
        return "source `activity_image` has {$rows} row(s) holding only a URL, no file (e.g. ids ".self::list($ids).') — OpenPNE 4 keeps an image as a file, so these are not migrated.';
    }

    public static function templateMessage(string $template, string $landing, int $rows): string
    {
        $known = in_array($template, self::KNOWN_TEMPLATES, true);

        return match (true) {
            $landing === 'none' => "source `activity_data` has {$rows} `{$template}` template row(s) in threads that are not migrated.",
            $known => "source `activity_data` has {$rows} `{$template}` template row(s) landing in {$landing}: rendered into the OpenPNE 4 wording with a link after the walk.",
            default => "source `activity_data` has {$rows} `{$template}` template row(s) landing in {$landing} — OpenPNE 4 knows no such template, so the stored body is kept as it is.",
        };
    }

    /** @return array{int, list<int>} rows matching $where over `activity_data`, and the first ids */
    private function rows(string $where): array
    {
        $from = 'FROM '.SourceRef::table('activity_data').' AS `activity_data` WHERE '.$where;
        $count = (int) DB::scalar($this->resolve("SELECT COUNT(*) {$from}"));
        if ($count === 0) {
            return [0, []];
        }

        $ids = array_map(
            static fn (object $r): int => (int) $r->id,
            DB::select($this->resolve("SELECT `activity_data`.`id` AS `id` {$from} ORDER BY `activity_data`.`id` LIMIT ".self::SAMPLE)),
        );

        return [$count, $ids];
    }

    /** @return array<int|string, array{int, list<int>}> value => [rows, first ids] */
    private function grouped(string $column, string $where): array
    {
        $from = 'FROM '.SourceRef::table('activity_data').' AS `activity_data` WHERE '.$where;
        $result = [];
        foreach (DB::select($this->resolve("SELECT {$column} AS `value`, COUNT(*) AS `rows` {$from} GROUP BY {$column} ORDER BY {$column}")) as $group) {
            $ids = array_map(
                static fn (object $r): int => (int) $r->id,
                DB::select($this->resolve("SELECT `activity_data`.`id` AS `id` {$from} AND {$column} = ? ORDER BY `activity_data`.`id` LIMIT ".self::SAMPLE), [$group->value]),
            );
            $result[$group->value] = [(int) $group->rows, $ids];
        }

        return $result;
    }

    private function resolve(string $sql): string
    {
        return (new InsertSelectCompiler)->resolveSourceRefs($sql, $this->prefix, $this->database);
    }

    /** @param  list<int>  $ids */
    private static function list(array $ids): string
    {
        return implode(', ', $ids);
    }
}
