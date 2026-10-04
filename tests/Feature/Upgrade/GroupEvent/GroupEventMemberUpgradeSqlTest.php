<?php

namespace Tests\Feature\Upgrade\GroupEvent;

use App\Models\GroupEvent;
use App\Models\Member;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Steps\GroupEventMemberUpgrade;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Upgrade\UpgradeSqlTestCase;

class GroupEventMemberUpgradeSqlTest extends UpgradeSqlTestCase
{
    protected function sourceTables(): array
    {
        return ['community_event_member'];
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
