<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Auth\SessionRevocation;
use App\Models\AdminUser;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SessionRevocation targets the stable per-realm tables. The colliding-id cases pin
 * the reason those keys exist: sessions.user_id / admin_sessions.user_id are plain
 * integers, so member id N and admin id N are different principals and revoking one
 * must never touch the other's rows.
 */
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

    public function test_member_revocation_purges_member_rows_only_and_rotates_the_token(): void
    {
        $member = Member::factory()->create();
        $before = $member->remember_token;
        $this->seedRow('sessions', 'member-device', (int) $member->getKey());
        // An administrator whose id collides with the member's id must be unaffected.
        $this->seedRow('admin_sessions', 'admin-device', (int) $member->getKey());

        SessionRevocation::revokeMember($member);

        $this->assertDatabaseMissing('sessions', ['id' => 'member-device']);
        $this->assertDatabaseHas('admin_sessions', ['id' => 'admin-device']);
        $this->assertNotSame($before, $member->fresh()->remember_token);
    }

    public function test_admin_revocation_purges_admin_rows_only_and_honors_the_exception(): void
    {
        $admin = AdminUser::factory()->create();
        $before = $admin->remember_token;
        $this->seedRow('admin_sessions', 'current-device', (int) $admin->getKey());
        $this->seedRow('admin_sessions', 'other-device', (int) $admin->getKey());
        $this->seedRow('sessions', 'member-device', (int) $admin->getKey());

        SessionRevocation::revokeAdmin($admin, 'current-device');

        $this->assertDatabaseHas('admin_sessions', ['id' => 'current-device']);
        $this->assertDatabaseMissing('admin_sessions', ['id' => 'other-device']);
        $this->assertDatabaseHas('sessions', ['id' => 'member-device']);
        $this->assertNotSame($before, $admin->fresh()->remember_token);
    }

    public function test_the_purge_is_a_no_op_off_the_database_driver(): void
    {
        config(['session.driver' => 'file']);
        $member = Member::factory()->create();
        $before = $member->remember_token;
        $this->seedRow('sessions', 'member-device', (int) $member->getKey());

        SessionRevocation::revokeMember($member);

        // The token still rotates (remember-me cookies die on every driver); only the
        // row purge is driver-specific.
        $this->assertDatabaseHas('sessions', ['id' => 'member-device']);
        $this->assertNotSame($before, $member->fresh()->remember_token);
    }
}
