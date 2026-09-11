<?php

namespace Tests\Feature\Schema;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** RefreshDatabase is required: the SQLite lane is in-memory, and an unmigrated schema would pass vacuously. */
class TimeAxisIndexesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_site_wide_posting_time_list_has_its_axis_index(): void
    {
        foreach (['diaries', 'members', 'groups', 'timeline_posts'] as $table) {
            $this->assertContains(['created_at', 'id'], $this->indexColumns($table), $table);
        }
    }

    public function test_the_member_scoped_axes_keep_their_index(): void
    {
        $this->assertContains(['member_id', 'created_at'], $this->indexColumns('diaries'));
        $this->assertContains(['member_id', 'created_at'], $this->indexColumns('timeline_posts'));
        $this->assertContains(['group_id', 'created_at', 'id'], $this->indexColumns('group_messages'));
    }

    public function test_no_index_leads_with_the_unused_thread_id(): void
    {
        $this->assertNotContains('thread_id', array_column($this->indexColumns('direct_messages'), 0));
        $this->assertGreaterThan(0, count($this->indexColumns('direct_messages')));
    }

    /** @return list<list<string>> */
    private function indexColumns(string $table): array
    {
        return array_values(array_map(fn (array $index) => $index['columns'], Schema::getIndexes($table)));
    }
}
