<?php

namespace Tests\Feature\Upgrade\CommunityEvent;

use App\Models\Community;
use App\Models\CommunityEvent;
use App\Models\Member;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\CommunityEventUpgrade;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs the compiled community_event INSERT...SELECT against the real OpenPNE 3 `community_event` DDL.
 *
 * MySQL only: the set-based copy and the source DDL (TEXT, DATETIME, utf8mb3) are MySQL features.
 * Uses DatabaseMigrations rather than RefreshDatabase because creating the source table is DDL,
 * which implicitly commits transactions.
 */
class CommunityEventUpgradeSqlTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Upgrade INSERT...SELECT runs on MySQL (source DDL + set-based copy).');
        }

        // The real OpenPNE 3 `community_event` DDL, minus its FKs to `community`/`member` so the
        // source table stands alone; the migrated `communities`/`members` rows satisfy the
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
        $community = Community::factory()->create();
        $author = Member::factory()->create();
        $this->seedSourceEvent(555, $community->getKey(), $author->getKey(), [
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
        $this->assertDatabaseHas('community_events', [
            'id' => 555,
            'community_id' => $community->getKey(),
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
        $community = Community::factory()->create();
        $this->seedSourceEvent(1, $community->getKey(), null);

        $this->runUpgrade();

        $this->assertNull(CommunityEvent::findOrFail(1)->member_id);
    }

    public function test_carries_null_optional_scheduling_fields(): void
    {
        // application_deadline, capacity and event_updated_at are nullable; an open event without a
        // deadline / cap / prior edit must import with those columns NULL.
        $community = Community::factory()->create();
        $this->seedSourceEvent(1, $community->getKey(), null, [
            'application_deadline' => null,
            'capacity' => null,
            'event_updated_at' => null,
        ]);

        $this->runUpgrade();

        $event = CommunityEvent::findOrFail(1);
        $this->assertNull($event->application_deadline);
        $this->assertNull($event->capacity);
        $this->assertNull($event->event_updated_at);
    }

    public function test_preserves_long_text_fields(): void
    {
        // name/body/open_date_comment/area are TEXT in OpenPNE 3; >255-char values must not truncate.
        $community = Community::factory()->create();
        $longBody = str_repeat('本文', 5000);
        $longArea = str_repeat('場', 500);
        $this->seedSourceEvent(1, $community->getKey(), null, ['body' => $longBody, 'area' => $longArea]);

        $this->runUpgrade();

        $event = CommunityEvent::findOrFail(1);
        $this->assertSame($longBody, $event->body);
        $this->assertSame($longArea, $event->area);
    }

    private function runUpgrade(): void
    {
        DB::statement((new InsertSelectCompiler)->compile(new CommunityEventUpgrade));
    }

    private function seedSourceEvent(int $id, int $communityId, ?int $memberId, array $overrides = []): void
    {
        DB::table('community_event')->insert(array_merge([
            'id' => $id,
            'community_id' => $communityId,
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
