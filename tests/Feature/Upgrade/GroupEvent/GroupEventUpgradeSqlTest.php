<?php

namespace Tests\Feature\Upgrade\GroupEvent;

use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\Member;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\GroupEventUpgrade;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\TestCase;

/**
 * Runs the compiled INSERT...SELECT against the real OpenPNE 3 `community_event` DDL, MySQL only.
 * MigratesUpgradeTargetsOnce rather than RefreshDatabase, because creating the source table is DDL
 * and implicitly commits.
 */
class GroupEventUpgradeSqlTest extends TestCase
{
    use MigratesUpgradeTargetsOnce;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Upgrade INSERT...SELECT runs on MySQL (source DDL + set-based copy).');
        }

        // The real OpenPNE 3 `community_event` DDL, minus its FKs to `community`/`member` so the
        // source table stands alone; the migrated `groups`/`members` rows satisfy the
        // target-side FKs instead.
        DB::statement('DROP TABLE IF EXISTS `community_event`');
        DB::statement(SourceSchema::default()->createStatement('community_event', withoutForeignKeys: true));
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('DROP TABLE IF EXISTS `community_event`');
        }

        parent::tearDown();
    }

    public function test_preserves_id_community_author_scheduling_and_timestamps(): void
    {
        $group = Group::factory()->create();
        $author = Member::factory()->create();
        $this->seedSourceEvent(555, $group->getKey(), $author->getKey(), [
            'name' => 'Morning run',
            'body' => 'Meet at the gate.',
            'open_date' => '2020-05-06 00:00:00',
            'open_date_comment' => '07:00 start',
            'area' => 'Yoyogi Park',
            'application_deadline' => '2020-05-05 00:00:00',
            'capacity' => 12,
            'event_updated_at' => '2020-04-01 09:08:07',
        ]);

        $this->runUpgrade();

        // id, content, the scheduling fields, the activity timestamp and the post dates come from
        // the source row, not the upgrade run's clock.
        $this->assertDatabaseHas('group_events', [
            'id' => 555,
            'group_id' => $group->getKey(),
            'member_id' => $author->getKey(),
            'name' => 'Morning run',
            'body' => 'Meet at the gate.',
            'open_date' => '2020-05-06 00:00:00',
            'open_date_comment' => '07:00 start',
            'area' => 'Yoyogi Park',
            'application_deadline' => '2020-05-05 00:00:00',
            'capacity' => 12,
            'event_updated_at' => '2020-04-01 09:08:07',
            'created_at' => '2018-03-04 12:34:56',
            'updated_at' => '2019-06-07 01:02:03',
        ]);
    }

    public function test_keeps_event_of_a_withdrawn_author_with_null_member(): void
    {
        // OpenPNE 3 sets member_id NULL when the author withdraws but keeps the event.
        $group = Group::factory()->create();
        $this->seedSourceEvent(1, $group->getKey(), null);

        $this->runUpgrade();

        $this->assertNull(GroupEvent::findOrFail(1)->member_id);
    }

    public function test_carries_null_optional_scheduling_fields(): void
    {
        // application_deadline, capacity and event_updated_at are nullable; an open event without a
        // deadline / cap / prior edit must import with those columns NULL.
        $group = Group::factory()->create();
        $this->seedSourceEvent(1, $group->getKey(), null, [
            'application_deadline' => null,
            'capacity' => null,
            'event_updated_at' => null,
        ]);

        $this->runUpgrade();

        $event = GroupEvent::findOrFail(1);
        $this->assertNull($event->application_deadline);
        $this->assertNull($event->capacity);
        $this->assertNull($event->event_updated_at);
    }

    public function test_preserves_long_text_fields(): void
    {
        // name/body/open_date_comment/area are TEXT in OpenPNE 3; >255-char values must not truncate.
        $group = Group::factory()->create();
        $longBody = str_repeat('本文', 5000);
        $longArea = str_repeat('場', 500);
        $this->seedSourceEvent(1, $group->getKey(), null, ['body' => $longBody, 'area' => $longArea]);

        $this->runUpgrade();

        $event = GroupEvent::findOrFail(1);
        $this->assertSame($longBody, $event->body);
        $this->assertSame($longArea, $event->area);
    }

    private function runUpgrade(): void
    {
        DB::statement((new InsertSelectCompiler)->compile(new GroupEventUpgrade));
    }

    private function seedSourceEvent(int $id, int $groupId, ?int $memberId, array $overrides = []): void
    {
        DB::table('community_event')->insert(array_merge([
            'id' => $id,
            'community_id' => $groupId,
            'member_id' => $memberId,
            'name' => 'Legacy event',
            'body' => 'Legacy body',
            'event_updated_at' => '2019-06-07 01:02:03',
            'open_date' => '2019-07-01 00:00:00',
            'open_date_comment' => '18:00-',
            'area' => 'Shibuya',
            'application_deadline' => '2019-06-30 00:00:00',
            'capacity' => 20,
            'created_at' => '2018-03-04 12:34:56',
            'updated_at' => '2019-06-07 01:02:03',
        ], $overrides));
    }
}
