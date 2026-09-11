<?php

namespace App\Upgrade\Verify;

use App\Features\Group\BoardBumpedAt;
use App\Models\GroupEvent;
use App\Models\GroupTopic;
use Closure;

/**
 * Every thread's `bumped_at` must equal its definition (docs/internals/upgrade.md, "Verify"). The
 * definition is the whole truth, so no checkpoint is consulted: a site migrated before the pass
 * existed verifies on its rows alone.
 */
final class BoardBumpCheck
{
    /** @var array<string, array{class-string, string}> thread table => [model, comments table] */
    private const BOARDS = [
        'group_topics' => [GroupTopic::class, 'group_topic_comments'],
        'group_events' => [GroupEvent::class, 'group_event_comments'],
    ];

    /**
     * @param  list<string>  $targetTables
     * @param  Closure(string, bool, string): void  $record
     */
    public function verify(array $targetTables, Closure $record): void
    {
        $boards = array_filter(self::BOARDS, fn (array $board, string $table) => in_array($table, $targetTables, true) && in_array($board[1], $targetTables, true), ARRAY_FILTER_USE_BOTH);
        if ($boards === []) {
            return;
        }

        $drift = 0;
        foreach ($boards as [$model]) {
            $drift += BoardBumpedAt::drift($model);
        }

        $record('board_bump', $drift === 0, $drift === 0
            ? 'every topic and event sits at its last comment'
            : "{$drift} thread(s) have a bumped_at that is not their last comment's time");
    }
}
