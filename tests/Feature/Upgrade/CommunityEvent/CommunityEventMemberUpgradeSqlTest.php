<?php

namespace Tests\Feature\Upgrade\CommunityEvent;

use App\Models\CommunityEvent;
use App\Models\Member;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\CommunityEventMemberUpgrade;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs the compiled community_event_member (RSVP pivot) INSERT...SELECT against the real OpenPNE 3
 * `community_event_member` DDL.
 *
 * MySQL only: the set-based copy and the source DDL (DATETIME, utf8mb3) are MySQL features. Uses
 * DatabaseMigrations rather than RefreshDatabase because creating the source table is DDL, which
 * implicitly commits transactions.
 */
class CommunityEventMemberUpgradeSqlTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Upgrade INSERT...SELECT runs on MySQL (source DDL + set-based copy).');
        }

        // The real OpenPNE 3 `community_event_member` DDL, minus its FKs to `community_event`/`member`
        // so the source table stands alone; the migrated `community_events`/`members` rows satisfy the
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
        $event = CommunityEvent::factory()->create();
        $member = Member::factory()->create();
        $this->seedSourceMember(42, $event->getKey(), $member->getKey());

        $this->runUpgrade();

        // Row presence is the RSVP; id, the (event, member) pair and the join dates carry over.
        $this->assertDatabaseHas('community_event_members', [
            'id' => 42,
            'community_event_id' => $event->getKey(),
            'member_id' => $member->getKey(),
            'created_at' => '2018-03-04 12:34:56',
            'updated_at' => '2019-06-07 01:02:03',
        ]);
    }

    public function test_imports_every_attendee_of_an_event(): void
    {
        $event = CommunityEvent::factory()->create();
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
        DB::statement((new InsertSelectCompiler)->compile(new CommunityEventMemberUpgrade));
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
