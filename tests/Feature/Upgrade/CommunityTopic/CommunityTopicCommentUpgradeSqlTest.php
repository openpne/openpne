<?php

namespace Tests\Feature\Upgrade\CommunityTopic;

use App\Models\CommunityTopic;
use App\Models\CommunityTopicComment;
use App\Models\Member;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\CommunityTopicCommentUpgrade;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs the compiled community_topic_comment INSERT...SELECT against the real OpenPNE 3
 * `community_topic_comment` DDL.
 *
 * MySQL only: the set-based copy and the source DDL (TEXT, DATETIME, utf8mb3) are MySQL features.
 * Uses DatabaseMigrations rather than RefreshDatabase because creating the source table is DDL,
 * which implicitly commits transactions.
 */
class CommunityTopicCommentUpgradeSqlTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Upgrade INSERT...SELECT runs on MySQL (source DDL + set-based copy).');
        }

        // The real OpenPNE 3 `community_topic_comment` DDL, minus its FKs to `community_topic`/
        // `member` so the source table stands alone; the migrated `community_topics`/`members` rows
        // satisfy the target-side FKs instead.
        DB::statement('DROP TABLE IF EXISTS `community_topic_comment`');
        DB::statement(SourceSchema::default()->createStatement('community_topic_comment', withoutForeignKeys: true));
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('DROP TABLE IF EXISTS `community_topic_comment`');
        }

        parent::tearDown();
    }

    public function test_preserves_id_topic_author_number_and_timestamps(): void
    {
        $author = Member::factory()->create();
        $topic = CommunityTopic::factory()->create();
        $this->seedSourceComment(987, $topic->getKey(), $author->getKey(), ['number' => 3]);

        $this->runUpgrade();

        // id, number and timestamps come from the source row, not the upgrade run's clock.
        $this->assertDatabaseHas('community_topic_comments', [
            'id' => 987,
            'community_topic_id' => $topic->getKey(),
            'member_id' => $author->getKey(),
            'number' => 3,
            'created_at' => '2018-03-04 12:34:56',
            'updated_at' => '2019-06-07 01:02:03',
        ]);
    }

    public function test_keeps_comment_of_a_withdrawn_author_with_null_member(): void
    {
        // OpenPNE 3 sets member_id NULL when the author withdraws but keeps the comment.
        $topic = CommunityTopic::factory()->create();
        $this->seedSourceComment(1, $topic->getKey(), null);

        $this->runUpgrade();

        $this->assertNull(CommunityTopicComment::findOrFail(1)->member_id);
    }

    public function test_preserves_long_text_body(): void
    {
        // OpenPNE 3 community_topic_comment.body is TEXT; a >255-char value must not truncate.
        $topic = CommunityTopic::factory()->create();
        $longBody = str_repeat('本文', 5000);
        $this->seedSourceComment(1, $topic->getKey(), null, ['body' => $longBody]);

        $this->runUpgrade();

        $this->assertSame($longBody, CommunityTopicComment::findOrFail(1)->body);
    }

    public function test_imports_legacy_duplicate_number_losslessly(): void
    {
        // OpenPNE 3's `number` is a racy max+1 on a non-unique index, so legacy data can
        // carry duplicate (community_topic_id, number); the import must keep both rows.
        $topic = CommunityTopic::factory()->create();
        $this->seedSourceComment(1, $topic->getKey(), null, ['number' => 5]);
        $this->seedSourceComment(2, $topic->getKey(), null, ['number' => 5]);

        $this->runUpgrade();

        $this->assertDatabaseCount('community_topic_comments', 2);
        $this->assertSame(5, CommunityTopicComment::findOrFail(1)->number);
        $this->assertSame(5, CommunityTopicComment::findOrFail(2)->number);
    }

    private function runUpgrade(): void
    {
        DB::statement((new InsertSelectCompiler)->compile(new CommunityTopicCommentUpgrade));
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
