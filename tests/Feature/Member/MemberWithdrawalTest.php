<?php

namespace Tests\Feature\Member;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CapturesSecurityLog;
use Tests\TestCase;

class MemberWithdrawalTest extends TestCase
{
    use CapturesSecurityLog;
    use RefreshDatabase;

    public function test_a_guest_cannot_post_the_withdrawal(): void
    {
        $this->post('/member/config/withdrawal', ['password' => 'password', 'confirm' => '1'])
            ->assertRedirect('/login');
    }

    public function test_withdrawing_deletes_the_member_and_logs_out(): void
    {
        Member::factory()->create(['id' => 1]); // reserve the un-withdrawable primary
        $member = Member::factory()->create();
        $this->actingAs($member);

        $this->post('/member/config/withdrawal', ['password' => 'password', 'confirm' => '1'])
            ->assertRedirect(route('login'));

        $this->assertDatabaseMissing('members', ['id' => $member->id]);
        $this->get('/member/config')->assertRedirect('/login'); // logged out
    }

    public function test_withdrawing_rejects_a_wrong_password(): void
    {
        Member::factory()->create(['id' => 1]);
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/withdrawal', [
            'password' => 'not-the-password',
            'confirm' => '1',
        ])->assertSessionHasErrors('password');

        $this->assertModelExists($member);
    }

    public function test_withdrawing_requires_the_confirmation_checkbox(): void
    {
        Member::factory()->create(['id' => 1]);
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/withdrawal', ['password' => 'password'])
            ->assertSessionHasErrors('confirm');

        $this->assertModelExists($member);
    }

    public function test_the_primary_member_cannot_withdraw(): void
    {
        // id 1 is never withdrawable; rejected before the service so it is a 403, not a 500.
        $primary = Member::factory()->create(['id' => 1]);

        $this->actingAs($primary)->post('/member/config/withdrawal', [
            'password' => 'password',
            'confirm' => '1',
        ])->assertForbidden();

        $this->assertModelExists($primary);
    }

    public function test_the_leave_url_redirects_to_the_withdrawal_category(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/leave')
            ->assertRedirect(route('member.config', ['category' => 'withdrawal']));
    }

    public function test_withdrawing_purges_the_members_database_sessions(): void
    {
        // sessions.user_id has no FK, so deleting the member leaves rows behind; on the database driver
        // the withdrawal purges the member's other-device sessions outright.
        config()->set('session.driver', 'database');
        Member::factory()->create(['id' => 1]);
        $member = Member::factory()->create();
        DB::table('sessions')->insert([
            'id' => 'other-device-session',
            'user_id' => $member->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'agent',
            'payload' => 'x',
            'last_activity' => 1700000000,
        ]);

        $this->actingAs($member)->post('/member/config/withdrawal', [
            'password' => 'password',
            'confirm' => '1',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseMissing('sessions', ['id' => 'other-device-session']);
    }
}
