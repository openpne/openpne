<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Auth\SessionRevocation;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
    }

    private function seedRow(string $table, string $id, int $userId): void
    {
        DB::table($table)->insert([
            'id' => $id,
            'user_id' => $userId,
            'payload' => base64_encode('{}'),
            'last_activity' => time(),
        ]);
    }

    public function test_member_revocation_purges_that_members_rows_and_honors_the_exception(): void
    {
        $member = Member::factory()->create();
        $other = Member::factory()->create();
        $this->seedRow('sessions', 'current-device', (int) $member->getKey());
        $this->seedRow('sessions', 'other-device', (int) $member->getKey());
        $this->seedRow('sessions', 'someone-else', (int) $other->getKey());

        SessionRevocation::revokeMember($member, 'current-device');

        $this->assertDatabaseHas('sessions', ['id' => 'current-device']);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
        $this->assertDatabaseHas('sessions', ['id' => 'someone-else']);
    }

    public function test_the_purge_is_a_no_op_off_the_database_driver(): void
    {
        config(['session.driver' => 'file']);
        $member = Member::factory()->create();
        $this->seedRow('sessions', 'member-device', (int) $member->getKey());

        SessionRevocation::revokeMember($member);

        $this->assertDatabaseHas('sessions', ['id' => 'member-device']);
    }
}
