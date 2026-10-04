<?php

namespace Tests\Feature\Upgrade\GroupTopic;

use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\Member;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Steps\GroupTopicCommentUpgrade;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Upgrade\UpgradeSqlTestCase;

class GroupTopicCommentUpgradeSqlTest extends UpgradeSqlTestCase
{
    protected function sourceTables(): array
    {
        return ['community_topic_comment'];
    }

    public function test_preserves_id_topic_author_number_and_timestamps(): void
    {
        $author = Member::factory()->create();
        $topic = GroupTopic::factory()->create();
        $this->seedSourceComment(987, $topic->getKey(), $author->getKey(), ['number' => 3]);

        $this->runUpgrade();

        // id, number and timestamps come from the source row, not the upgrade run's clock.
        $this->assertDatabaseHas('group_topic_comments', [
            'id' => 987,
            'group_topic_id' => $topic->getKey(),
            'member_id' => $author->getKey(),
            'number' => 3,
            'created_at' => '2018-03-04 12:34:56',
            'updated_at' => '2019-06-07 01:02:03',
        ]);
    }

    public function test_keeps_comment_of_a_withdrawn_author_with_null_member(): void
    {
        // OpenPNE 3 sets member_id NULL when the author withdraws but keeps the comment.
        $topic = GroupTopic::factory()->create();
        $this->seedSourceComment(1, $topic->getKey(), null);

        $this->runUpgrade();

        $this->assertNull(GroupTopicComment::findOrFail(1)->member_id);
    }

    public function test_preserves_long_text_body(): void
    {
        // OpenPNE 3 community_topic_comment.body is TEXT; a >255-char value must not truncate.
        $topic = GroupTopic::factory()->create();
        $longBody = str_repeat('本文', 5000);
        $this->seedSourceComment(1, $topic->getKey(), null, ['body' => $longBody]);

        $this->runUpgrade();

        $this->assertSame($longBody, GroupTopicComment::findOrFail(1)->body);
    }

    public function test_imports_legacy_duplicate_number_losslessly(): void
    {
        // OpenPNE 3's `number` is a racy max+1 on a non-unique index, so legacy data can
        // carry duplicate (community_topic_id, number); the import must keep both rows.
        $topic = GroupTopic::factory()->create();
        $this->seedSourceComment(1, $topic->getKey(), null, ['number' => 5]);
        $this->seedSourceComment(2, $topic->getKey(), null, ['number' => 5]);

        $this->runUpgrade();

        $this->assertDatabaseCount('group_topic_comments', 2);
        $this->assertSame(5, GroupTopicComment::findOrFail(1)->number);
        $this->assertSame(5, GroupTopicComment::findOrFail(2)->number);
    }

    private function runUpgrade(): void
    {
        DB::statement((new InsertSelectCompiler)->compile(new GroupTopicCommentUpgrade));
    }

    private function seedSourceComment(int $id, int $topicId, ?int $memberId, array $overrides = []): void
    {
        DB::table('community_topic_comment')->insert(array_merge([
            'id' => $id,
            'community_topic_id' => $topicId,
            'member_id' => $memberId,
            'number' => 1,
            'body' => 'Legacy comment',
            'created_at' => '2018-03-04 12:34:56',
            'updated_at' => '2019-06-07 01:02:03',
        ], $overrides));
    }
}
