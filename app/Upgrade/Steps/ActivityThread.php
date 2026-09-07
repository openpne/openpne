<?php

namespace App\Upgrade\Steps;

use App\Support\Visibility;
use App\Upgrade\SourceRef;

/**
 * The SQL an OpenPNE 3 activity row is routed by: its thread root decides whether the thread lands on
 * the timeline or in a group's talk, a reply carries the root's audience, and an image follows its
 * activity (docs/internals/upgrade.md, "Activity threads"). `$a` is the correlation name of the
 * activity row in the enclosing query.
 */
final class ActivityThread
{
    /** The root a reply can be walked up to in fixed SQL; a deeper chain is not migrated (preflight WARN). */
    public const MAX_DEPTH = 4;

    /** OpenPNE 3 ActivityDataTable::PUBLIC_FLAG_*: 0 open / 1 SNS / 2 friend / 3 private — not the diary scale, whose Open is 4. */
    public const MEMBERS_FLAG = 1;

    public static function parentExists(string $a): string
    {
        return 'EXISTS (SELECT 1 FROM '.SourceRef::table('activity_data')." AS `parent_row` WHERE `parent_row`.`id` = `{$a}`.`in_reply_to_activity_id`)";
    }

    /** SQL boolean: a reply whose parent row is gone (OpenPNE 3 cascades, so only an incomplete dump has one). */
    public static function isDangling(string $a): string
    {
        return "(`{$a}`.`in_reply_to_activity_id` IS NOT NULL AND NOT ".self::parentExists($a).')';
    }

    /** SQL boolean: the row starts a thread — no parent, or a parent the source no longer has. */
    public static function startsThread(string $a): string
    {
        return "(`{$a}`.`in_reply_to_activity_id` IS NULL OR NOT ".self::parentExists($a).')';
    }

    /**
     * The id of the row's thread root: itself when it starts a thread, else the first ancestor with
     * no parent (or whose parent is gone) within MAX_DEPTH hops, else NULL.
     */
    public static function rootId(string $a): string
    {
        $t = SourceRef::table('activity_data');

        return 'CASE WHEN '.self::startsThread($a)." THEN `{$a}`.`id` ELSE ("
            .'SELECT CASE'
            .' WHEN `p1`.`in_reply_to_activity_id` IS NULL OR `p2`.`id` IS NULL THEN `p1`.`id`'
            .' WHEN `p2`.`in_reply_to_activity_id` IS NULL OR `p3`.`id` IS NULL THEN `p2`.`id`'
            .' WHEN `p3`.`in_reply_to_activity_id` IS NULL OR `p4`.`id` IS NULL THEN `p3`.`id`'
            .' WHEN `p4`.`in_reply_to_activity_id` IS NULL OR NOT EXISTS (SELECT 1 FROM '.$t.' AS `p5` WHERE `p5`.`id` = `p4`.`in_reply_to_activity_id`) THEN `p4`.`id`'
            .' ELSE NULL END'
            ." FROM {$t} AS `p1`"
            ." LEFT JOIN {$t} AS `p2` ON `p2`.`id` = `p1`.`in_reply_to_activity_id`"
            ." LEFT JOIN {$t} AS `p3` ON `p3`.`id` = `p2`.`in_reply_to_activity_id`"
            ." LEFT JOIN {$t} AS `p4` ON `p4`.`id` = `p3`.`in_reply_to_activity_id`"
            ." WHERE `p1`.`id` = `{$a}`.`in_reply_to_activity_id`) END";
    }

    /** One column of the row's thread root (NULL when the root is out of reach). */
    public static function rootColumn(string $a, string $column): string
    {
        return '(SELECT `root_row`.`'.$column.'` FROM '.SourceRef::table('activity_data').' AS `root_row` WHERE `root_row`.`id` = '.self::rootId($a).')';
    }

    /** SQL boolean: the row's thread root exists and satisfies $predicate, written over the alias `root_row`. */
    public static function rootWhere(string $a, string $predicate): string
    {
        return 'EXISTS (SELECT 1 FROM '.SourceRef::table('activity_data').' AS `root_row` WHERE `root_row`.`id` = '.self::rootId($a)." AND ({$predicate}))";
    }

    public static function timelineRoot(): string
    {
        return '`root_row`.`foreign_table` IS NULL';
    }

    /** A community thread lands only when its group survives and the root was for every member: talk has no per-message audience. */
    public static function groupRoot(): string
    {
        return "`root_row`.`foreign_table` = 'community' AND `root_row`.`public_flag` = ".self::MEMBERS_FLAG
            .' AND EXISTS (SELECT 1 FROM '.SourceRef::table('community').' AS `thread_group` WHERE `thread_group`.`id` = `root_row`.`foreign_id`)';
    }

    public static function landsOnTimeline(string $a): string
    {
        return self::rootWhere($a, self::timelineRoot());
    }

    public static function landsInGroup(string $a): string
    {
        return self::rootWhere($a, self::groupRoot());
    }

    /** SQL boolean: some step copies the row. */
    public static function migrated(string $a): string
    {
        return '('.self::landsOnTimeline($a).' OR '.self::landsInGroup($a).')';
    }

    /** The activity flag onto Visibility, an identity CASE pinned to the enum; an unknown flag yields NULL so the NOT NULL column refuses it. */
    public static function visibilityCase(string $flagExpr): string
    {
        $arms = implode(' ', array_map(
            static fn (Visibility $v): string => sprintf('WHEN %d THEN %d', $v->value, $v->value),
            Visibility::cases(),
        ));

        return "CASE {$flagExpr} {$arms} ELSE NULL END";
    }

    /** SQL boolean over the alias `activity_image`: its activity lands where $landing (over the alias `activity_data`) says. */
    public static function imageOf(string $landing): string
    {
        return 'EXISTS (SELECT 1 FROM '.SourceRef::table('activity_data').' AS `activity_data`'
            .' WHERE `activity_data`.`id` = `activity_image`.`activity_data_id` AND '.$landing.')';
    }

    /** 1..N slot by id among the file-backed images of one activity, so a URL-only row leaves no hole. */
    public static function imageNumber(): string
    {
        return '(SELECT COUNT(*) FROM '.SourceRef::table('activity_image').' AS `i2`'
            .' WHERE `i2`.`activity_data_id` = `activity_image`.`activity_data_id` AND `i2`.`file_id` IS NOT NULL AND `i2`.`id` <= `activity_image`.`id`)';
    }

    /** @return array<string, string> the activity_data columns no record step copies */
    public static function recordGaps(): array
    {
        return [
            'uri' => 'Read by the ActivityTemplateTransform post-walk pass, which turns it into the OpenPNE 4 link of a rendered template row; a hand-written row has no link.',
            'template' => 'Read by the ActivityTemplateTransform post-walk pass; the body is rendered in PHP after the walk.',
            'template_param' => 'Read by the ActivityTemplateTransform post-walk pass (PHP-serialized parameters).',
            'is_pc' => 'Per-device display flag; OpenPNE 4 shows every row on every surface.',
            'is_mobile' => 'Per-device display flag; the feature-phone frontend is out of scope.',
            'source' => 'The "via" caption of an API-posted activity; OpenPNE 4 has no source attribution.',
            'source_uri' => 'The "via" link; OpenPNE 4 has no source attribution.',
            'activity_image' => 'Migrated by TimelinePostImageUpgrade / GroupMessageImageUpgrade (join-row steps), not the record steps.',
        ];
    }
}
