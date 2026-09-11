<?php

namespace App\Features\Group;

use App\Models\GroupEvent;
use App\Models\GroupTopic;
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

    public static function lift(GroupTopic|GroupEvent $thread): void
    {
        $now = now();
        $thread->bumped_at = $now;
        $thread->newQuery()->whereKey($thread->getKey())->toBase()->update(['bumped_at' => $now]);
    }

    /** Recomputes from the surviving comments, for the row named or for every row of the table. */
    public static function settle(GroupTopic|GroupEvent|string $threadOrModel, ?int $id = null): int
    {
        $model = is_string($threadOrModel) ? $threadOrModel : $threadOrModel::class;
        [$table, $comments, $fk] = self::BOARDS[$model];
        $where = $id === null && is_string($threadOrModel) ? '' : ' WHERE id = ?';
        $bindings = $where === '' ? [] : [$id ?? $threadOrModel->getKey()];

        return DB::update(
            "UPDATE {$table} SET bumped_at = COALESCE((SELECT MAX(c.created_at) FROM {$comments} AS c WHERE c.{$fk} = {$table}.id), created_at)".$where,
            $bindings,
        );
    }

    /** Rows whose bumped_at disagrees with the definition, for the verifier. */
    public static function drift(string $model): int
    {
        [$table, $comments, $fk] = self::BOARDS[$model];

        return (int) DB::scalar(
            "SELECT COUNT(*) FROM {$table} WHERE bumped_at <> COALESCE((SELECT MAX(c.created_at) FROM {$comments} AS c WHERE c.{$fk} = {$table}.id), created_at)"
        );
    }
}
