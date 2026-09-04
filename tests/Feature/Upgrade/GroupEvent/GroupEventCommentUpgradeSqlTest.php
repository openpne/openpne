<?php

namespace Tests\Feature\Upgrade\GroupEvent;

use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\Member;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\GroupEventCommentUpgrade;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\TestCase;

/**
 * Runs the compiled INSERT...SELECT against the real OpenPNE 3 `community_event_comment` DDL, MySQL
 * only. MigratesUpgradeTargetsOnce rather than RefreshDatabase, because creating the source table is
 * DDL and implicitly commits.
 */
class GroupEventCommentUpgradeSqlTest extends TestCase
{
    use MigratesUpgradeTargetsOnce;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Upgrade INSERT...SELECT runs on MySQL (source DDL + set-based copy).');
        }

        // The real OpenPNE 3 `community_event_comment` DDL, minus its FKs to `community_event`/
        // `member` so the source table stands alone; the migrated `group_events`/`members` rows
        // satisfy the target-side FKs instead.
        DB::statement('DROP TABLE IF EXISTS `community_event_comment`');
        DB::statement(SourceSchema::default()->createStatement('community_event_comment', withoutForeignKeys: true));
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('DROP TABLE IF EXISTS `community_event_comment`');
        }

        parent::tearDown();
    }

    public function test_preserves_id_event_author_number_and_timestamps(): void
    {
        $author = Member::factory()->create();
        $event = GroupEvent::factory()->create();
        $this->seedSourceComment(987, $event->getKey(), $author->getKey(), ['number' => 3]);

        $this->runUpgrade();

        // id, number and timestamps come from the source row, not the upgrade run's clock.
        $this->assertDatabaseHas('group_event_comments', [
            'id' => 987,
            'group_event_id' => $event->getKey(),
            'member_id' => $author->getKey(),
            'number' => 3,
            'created_at' => '2018-03-04 12:34:56',
            'updated_at' => '2019-06-07 01:02:03',
        ]);
    }

    public function test_keeps_comment_of_a_withdrawn_author_with_null_member(): void
    {
        // OpenPNE 3 sets member_id NULL when the author withdraws but keeps the comment.
        $event = GroupEvent::factory()->create();
        $this->seedSourceComment(1, $event->getKey(), null);

        $this->runUpgrade();

        $this->assertNull(GroupEventComment::findOrFail(1)->member_id);
    }

    public function test_preserves_long_text_body(): void
    {
        // OpenPNE 3 community_event_comment.body is TEXT; a >255-char value must not truncate.
        $event = GroupEvent::factory()->create();
        $longBody = str_repeat('本文', 5000);
        $this->seedSourceComment(1, $event->getKey(), null, ['body' => $longBody]);

        $this->runUpgrade();

        $this->assertSame($longBody, GroupEventComment::findOrFail(1)->body);
    }

    public function test_imports_legacy_duplicate_number_losslessly(): void
    {
        // OpenPNE 3's `number` is a racy max+1 on a non-unique index, so legacy data can
        // carry duplicate (community_event_id, number); the import must keep both rows.
        $event = GroupEvent::factory()->create();
        $this->seedSourceComment(1, $event->getKey(), null, ['number' => 5]);
        $this->seedSourceComment(2, $event->getKey(), null, ['number' => 5]);

        $this->runUpgrade();

        $this->assertDatabaseCount('group_event_comments', 2);
        $this->assertSame(5, GroupEventComment::findOrFail(1)->number);
        $this->assertSame(5, GroupEventComment::findOrFail(2)->number);
    }

    private function runUpgrade(): void
    {
        DB::statement((new InsertSelectCompiler)->compile(new GroupEventCommentUpgrade));
    }

    private function seedSourceComment(int $id, int $eventId, ?int $memberId, array $overrides = []): void
    {
        DB::table('community_event_comment')->insert(array_merge([
            'id' => $id,
            'community_event_id' => $eventId,
            'member_id' => $memberId,
            'number' => 1,
            'body' => 'Legacy comment',
            'created_at' => '2018-03-04 12:34:56',
            'updated_at' => '2019-06-07 01:02:03',
        ], $overrides));
    }
}
