<?php

namespace Tests\Feature\Group\Actions;

use App\Features\Group\Actions\JoinDefaultGroups;
use App\Models\Group;
use App\Models\Member;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\Concerns\OpensSecondConnection;
use Tests\TestCase;

class JoinDefaultGroupsLockTest extends TestCase
{
    use MigratesUpgradeTargetsOnce, OpensSecondConnection;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Two connections need a server; sqlite :memory: has one.');
        }

        $this->openSecondConnection();
    }

    protected function tearDown(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->closeSecondConnection();
        }

        parent::tearDown();
    }

    public function test_an_unset_committed_after_the_registration_took_its_snapshot_is_honoured(): void
    {
        $group = Group::factory()->create(['is_default' => true]);
        $member = Member::factory()->create();

        DB::transaction(function () use ($group, $member): void {
            // Registration's earlier reads fix the REPEATABLE READ snapshot before the admin's unset commits.
            $this->assertTrue((bool) Group::whereKey($group->getKey())->value('is_default'));
            $this->onSecondConnection(fn () => Group::whereKey($group->getKey())->update(['is_default' => false]));

            app(JoinDefaultGroups::class)($member);
        });

        $this->assertDatabaseMissing('group_members', ['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);
    }
}
