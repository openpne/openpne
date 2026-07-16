<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The (member_id, diary_id) index round-trips on the SQLite lane. down()'s MySQL 1553 handling
 * (re-adding a member_id backing index before dropping the composite) cannot be exercised here —
 * SQLite has no InnoDB foreign-key index adoption — but this pins that up/down/up does not error.
 */
class DiaryCommentsCommenterIndexTest extends TestCase
{
    use RefreshDatabase;

    private const INDEX = 'diary_comments_member_id_diary_id_index';

    public function test_the_commenter_index_round_trips(): void
    {
        $migration = require database_path('migrations/2026_07_17_000001_add_commenter_index_to_diary_comments_table.php');

        // RefreshDatabase has already run up().
        $this->assertContains(self::INDEX, $this->indexNames());

        $migration->down();
        $this->assertNotContains(self::INDEX, $this->indexNames());

        $migration->up();
        $this->assertContains(self::INDEX, $this->indexNames());
    }

    /** @return list<string> */
    private function indexNames(): array
    {
        return array_map(fn (array $index): string => $index['name'], Schema::getIndexes('diary_comments'));
    }
}
