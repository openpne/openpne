<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** RefreshDatabase is required: the SQLite lane is in-memory, and an unmigrated schema would pass vacuously. */
class TimeAxisIndexesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_site_wide_posting_time_list_has_its_axis_index(): void
    {
        foreach (['diaries', 'members', 'groups'] as $table) {
            $this->assertContains(['created_at', 'id'], $this->indexColumns($table), $table);
        }
    }

    public function test_the_timeline_axes_carry_the_reply_flag_and_nothing_else_leads_with_it(): void
    {
        $columns = $this->indexColumns('timeline_posts');

        $this->assertContains(['in_reply_to_id', 'created_at', 'id'], $columns);
        $this->assertNotContains(['in_reply_to_id'], $columns);
        $this->assertNotContains(['created_at', 'id'], $columns);
        $this->assertNotContains(['member_id', 'created_at'], $columns);
    }

    public function test_the_member_scoped_axes_keep_their_index(): void
    {
        $this->assertContains(['member_id', 'created_at'], $this->indexColumns('diaries'));
        $this->assertContains(['member_id', 'in_reply_to_id', 'created_at'], $this->indexColumns('timeline_posts'));
        $this->assertContains(['group_id', 'created_at', 'id'], $this->indexColumns('group_messages'));
    }

    public function test_no_index_leads_with_the_unused_thread_id(): void
    {
        $this->assertNotContains('thread_id', array_column($this->indexColumns('direct_messages'), 0));
        $this->assertContains(['sender_id', 'is_draft', 'sender_deleted_at'], $this->indexColumns('direct_messages'));
        $this->assertContains(['recipient_id', 'recipient_deleted_at'], $this->indexColumns('direct_message_recipients'));
    }

    /** @return list<list<string>> */
    private function indexColumns(string $table): array
    {
        return array_values(array_map(fn (array $index) => $index['columns'], Schema::getIndexes($table)));
    }
}
