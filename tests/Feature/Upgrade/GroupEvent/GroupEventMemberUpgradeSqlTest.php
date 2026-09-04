<?php

namespace Tests\Feature\Upgrade\GroupEvent;

use App\Models\GroupEvent;
use App\Models\Member;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\GroupEventMemberUpgrade;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\TestCase;

/**
 * Runs the compiled INSERT...SELECT against the real OpenPNE 3 `community_event_member` DDL, MySQL
 * only. MigratesUpgradeTargetsOnce rather than RefreshDatabase, because creating the source table is
 * DDL and implicitly commits.
 */
class GroupEventMemberUpgradeSqlTest extends TestCase
{
    use MigratesUpgradeTargetsOnce;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Upgrade INSERT...SELECT runs on MySQL (source DDL + set-based copy).');
        }

        // The real OpenPNE 3 `community_event_member` DDL, minus its FKs to `community_event`/`member`
        // so the source table stands alone; the migrated `group_events`/`members` rows satisfy the
        // target-side FKs instead.
        DB::statement('DROP TABLE IF EXISTS `community_event_member`');
        DB::statement(SourceSchema::default()->createStatement('community_event_member', withoutForeignKeys: true));
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('DROP TABLE IF EXISTS `community_event_member`');
        }

        parent::tearDown();
    }

    public function test_preserves_id_event_member_and_join_timestamps(): void
    {
        $event = GroupEvent::factory()->create();
        $member = Member::factory()->create();
        $this->seedSourceMember(42, $event->getKey(), $member->getKey());

        $this->runUpgrade();

        // Row presence is the RSVP; id, the (event, member) pair and the join dates carry over.
        $this->assertDatabaseHas('group_event_members', [
            'id' => 42,
            'group_event_id' => $event->getKey(),
            'member_id' => $member->getKey(),
            'created_at' => '2018-03-04 12:34:56',
            'updated_at' => '2019-06-07 01:02:03',
        ]);
    }

    public function test_imports_every_attendee_of_an_event(): void
    {
        $event = GroupEvent::factory()->create();
        $a = Member::factory()->create();
        $b = Member::factory()->create();
        $this->seedSourceMember(1, $event->getKey(), $a->getKey());
        $this->seedSourceMember(2, $event->getKey(), $b->getKey());

        $this->runUpgrade();

        $this->assertEqualsCanonicalizing(
            [$a->getKey(), $b->getKey()],
            $event->fresh()->participants->pluck('id')->all(),
        );
    }

    private function runUpgrade(): void
    {
        DB::statement((new InsertSelectCompiler)->compile(new GroupEventMemberUpgrade));
    }

    private function seedSourceMember(int $id, int $eventId, int $memberId, array $overrides = []): void
    {
        DB::table('community_event_member')->insert(array_merge([
            'id' => $id,
            'community_event_id' => $eventId,
            'member_id' => $memberId,
            'created_at' => '2018-03-04 12:34:56',
            'updated_at' => '2019-06-07 01:02:03',
        ], $overrides));
    }
}
