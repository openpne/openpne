<?php

namespace App\Features\Group;

use App\Models\GroupEvent;
use App\Models\GroupTopic;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * `bumped_at` is always `COALESCE(MAX(comments.created_at), created_at)`: a comment lifts the thread,
 * a deletion lets it settle back, nothing else moves it (docs/internals/group-boards.md, "The board
 * key is bumped_at"). Written through the query builder because any model save bumps `updated_at`.
 */
final class BoardBumpedAt
{
    /** @var array<class-string<Model>, array{string, string, string}> model => [table, comments table, comment FK] */
    private const BOARDS = [
        GroupTopic::class => ['group_topics', 'group_topic_comments', 'group_topic_id'],
        GroupEvent::class => ['group_events', 'group_event_comments', 'group_event_id'],
    ];

    /** The comment's own created_at, so the column equals its definition to the second. */
    public static function lift(GroupTopic|GroupEvent $thread, CarbonInterface $commentedAt): void
    {
        $thread->bumped_at = $commentedAt;
        $thread->newQuery()->whereKey($thread->getKey())->toBase()->update(['bumped_at' => $commentedAt]);
    }

    public static function settle(GroupTopic|GroupEvent $thread): void
    {
        [$table, $comments, $fk] = self::BOARDS[$thread::class];

        DB::update("UPDATE {$table} SET bumped_at = ".self::definition($table, $comments, $fk).' WHERE id = ?', [$thread->getKey()]);
    }

    /** @param  class-string<GroupTopic|GroupEvent>  $model */
    public static function settleAll(string $model): int
    {
        [$table, $comments, $fk] = self::BOARDS[$model];

        return DB::update("UPDATE {$table} SET bumped_at = ".self::definition($table, $comments, $fk));
    }

    /** The one SQL spelling of the definition; the migration that introduced the column carries the same text. */
    public static function definition(string $table, string $comments, string $fk): string
    {
        return "COALESCE((SELECT MAX(c.created_at) FROM {$comments} AS c WHERE c.{$fk} = {$table}.id), created_at)";
    }

    /** @param  class-string<GroupTopic|GroupEvent>  $model */
    public static function drift(string $model): int
    {
        [$table, $comments, $fk] = self::BOARDS[$model];

        return (int) DB::scalar("SELECT COUNT(*) FROM {$table} WHERE bumped_at <> ".self::definition($table, $comments, $fk));
    }
}
