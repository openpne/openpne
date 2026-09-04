<?php

namespace Tests\Feature\Upgrade\GroupTopic;

use App\Models\Group;
use App\Models\GroupTopic;
use App\Models\Member;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\GroupTopicUpgrade;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\TestCase;

/**
 * Runs the compiled INSERT...SELECT against the real OpenPNE 3 `community_topic` DDL, MySQL only.
 * MigratesUpgradeTargetsOnce rather than RefreshDatabase, because creating the source table is DDL
 * and implicitly commits.
 */
class GroupTopicUpgradeSqlTest extends TestCase
{
    use MigratesUpgradeTargetsOnce;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Upgrade INSERT...SELECT runs on MySQL (source DDL + set-based copy).');
        }

        // The real OpenPNE 3 `community_topic` DDL, minus its FKs to `community`/`member` so the
        // source table stands alone; the migrated `groups`/`members` rows satisfy the
        // target-side FKs instead.
        DB::statement('DROP TABLE IF EXISTS `community_topic`');
        DB::statement(SourceSchema::default()->createStatement('community_topic', withoutForeignKeys: true));
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('DROP TABLE IF EXISTS `community_topic`');
        }

        parent::tearDown();
    }

    public function test_preserves_id_community_author_content_and_timestamps(): void
    {
        $group = Group::factory()->create();
        $author = Member::factory()->create();
        $this->seedSourceTopic(555, $group->getKey(), $author->getKey(), [
            'name' => 'Weekend plans',
            'body' => 'Where are we running?',
            'topic_updated_at' => '2020-05-06 07:08:09',
        ]);

        $this->runUpgrade();

        // id, content, the activity timestamp and the post dates come from the source row.
        $this->assertDatabaseHas('group_topics', [
            'id' => 555,
            'group_id' => $group->getKey(),
            'member_id' => $author->getKey(),
            'name' => 'Weekend plans',
            'body' => 'Where are we running?',
            'topic_updated_at' => '2020-05-06 07:08:09',
            'created_at' => '2018-03-04 12:34:56',
            'updated_at' => '2019-06-07 01:02:03',
        ]);
    }

    public function test_keeps_topic_of_a_withdrawn_author_with_null_member(): void
    {
        // OpenPNE 3 sets member_id NULL when the author withdraws but keeps the topic.
        $group = Group::factory()->create();
        $this->seedSourceTopic(1, $group->getKey(), null);

        $this->runUpgrade();

        $this->assertNull(GroupTopic::findOrFail(1)->member_id);
    }

    public function test_carries_a_null_activity_timestamp(): void
    {
        // OpenPNE 3 leaves topic_updated_at NULL until the first content edit / comment.
        $group = Group::factory()->create();
        $this->seedSourceTopic(1, $group->getKey(), null, ['topic_updated_at' => null]);

        $this->runUpgrade();

        $this->assertNull(GroupTopic::findOrFail(1)->topic_updated_at);
    }

    public function test_preserves_long_text_name_and_body(): void
    {
        // OpenPNE 3 community_topic.name/body are TEXT; >255-char values must not truncate.
        $group = Group::factory()->create();
        $longName = str_repeat('題', 500);
        $longBody = str_repeat('本文', 5000);
        $this->seedSourceTopic(1, $group->getKey(), null, ['name' => $longName, 'body' => $longBody]);

        $this->runUpgrade();

        $topic = GroupTopic::findOrFail(1);
        $this->assertSame($longName, $topic->name);
        $this->assertSame($longBody, $topic->body);
    }

    private function runUpgrade(): void
    {
        DB::statement((new InsertSelectCompiler)->compile(new GroupTopicUpgrade));
    }

    private function seedSourceTopic(int $id, int $groupId, ?int $memberId, array $overrides = []): void
    {
        DB::table('community_topic')->insert(array_merge([
            'id' => $id,
            'community_id' => $groupId,
            'member_id' => $memberId,
            'name' => 'Legacy topic',
            'body' => 'Legacy body',
            'topic_updated_at' => '2019-06-07 01:02:03',
            'created_at' => '2018-03-04 12:34:56',
            'updated_at' => '2019-06-07 01:02:03',
        ], $overrides));
    }
}
