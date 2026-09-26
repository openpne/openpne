<?php

namespace Tests\Feature\Member;

use App\Features\Member\Actions\ConfirmEmailChange;
use App\Models\EmailChangeRequest;
use App\Models\Member;
use App\Notifications\Member\EmailChangeConfirmationNotification;
use App\Notifications\Member\EmailChangeNoticeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CapturesSecurityLog;
use Tests\TestCase;

class MemberEmailChangeTest extends TestCase
{
    use CapturesSecurityLog;
    use RefreshDatabase;

    public function test_a_guest_cannot_request_an_email_change(): void
    {
        $this->post('/member/config/email', ['password' => 'password', 'new_email' => 'new@example.com'])
            ->assertRedirect('/login');
    }

    public function test_requesting_an_email_change_stores_a_pending_row_and_mails_both_addresses(): void
    {
        Notification::fake();
        $this->captureSecurityLog();
        $member = Member::factory()->create();
        $old = $member->email;

        $this->actingAs($member)->post('/member/config/email', [
            'password' => 'password',
            'new_email' => 'new@example.com',
        ])->assertRedirect(route('member.config', ['category' => 'email']));

        // The new address is the subject of an email change, so it is logged (unlike a password).
        $this->assertSame('new@example.com', $this->assertOneSecurityEvent('email.change_requested')['new_email']);

        $this->assertDatabaseHas('email_change_requests', [
            'member_id' => $member->id, 'new_email' => 'new@example.com',
        ]);
        // The login email is not touched until confirmation.
        $this->assertSame($old, $member->fresh()->email);

        // Confirmation to the NEW address, notice to the OLD address (both pinned literals).
        Notification::assertSentOnDemand(
            EmailChangeConfirmationNotification::class,
            fn ($n, $channels, $notifiable): bool => ($notifiable->routes['mail'] ?? null) === 'new@example.com',
        );
        // The old-address notice carries the raw cancel token whose hash is the stored cancel_token.
        $row = EmailChangeRequest::firstWhere('member_id', $member->id);
        $this->assertNotNull($row?->cancel_token);
        Notification::assertSentOnDemand(
            EmailChangeNoticeNotification::class,
            fn (EmailChangeNoticeNotification $n, $channels, $notifiable): bool => ($notifiable->routes['mail'] ?? null) === $old
                && hash('sha256', $n->rawCancelToken) === $row->cancel_token,
        );
    }

    public function test_requesting_an_email_change_rejects_a_wrong_password(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/email', [
            'password' => 'not-the-password',
            'new_email' => 'new@example.com',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('email_change_requests', ['member_id' => $member->id]);
    }

    public function test_requesting_an_email_change_rejects_the_current_address(): void
    {
        $member = Member::factory()->create(['email' => 'me@example.com']);

        $this->actingAs($member)->post('/member/config/email', [
            'password' => 'password',
            'new_email' => 'ME@example.com', // case-insensitive match to the current address
        ])->assertSessionHasErrors('new_email');

        $this->assertDatabaseMissing('email_change_requests', ['member_id' => $member->id]);
    }

    public function test_requesting_an_email_change_rejects_an_in_use_address(): void
    {
        Member::factory()->create(['email' => 'taken@example.com']);
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/email', [
            'password' => 'password',
            'new_email' => 'TAKEN@example.com', // case-insensitive collision
        ])->assertSessionHasErrors('new_email');

        $this->assertDatabaseMissing('email_change_requests', ['member_id' => $member->id]);
    }

    public function test_the_confirm_landing_carries_the_no_referrer_header(): void
    {
        $member = Member::factory()->create();
        $raw = str_repeat('a', 40);
        EmailChangeRequest::create([
            'member_id' => $member->id, 'new_email' => 'new@example.com',
            'token' => hash('sha256', $raw), 'created_at' => now(),
        ]);

        $this->get('/member/config/email/confirm/'.$raw)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_the_confirm_form_renders_for_a_valid_token(): void
    {
        $member = Member::factory()->create();
        $raw = str_repeat('a', 40);
        EmailChangeRequest::create([
            'member_id' => $member->id, 'new_email' => 'new@example.com',
            'token' => hash('sha256', $raw), 'created_at' => now(),
        ]);

        // Reachable without auth, rendered as a pre-login page in the Classic shell.
        $this->get('/member/config/email/confirm/'.$raw)
            ->assertOk()
            ->assertSee('id="page_member_emailChangeConfirm"', false)
            ->assertSee('class="insecure_page"', false)
            ->assertSee('new@example.com')
            ->assertSee(route('member.config.email.confirm.submit', ['token' => $raw]), false);
        $this->assertSame('new@example.com', EmailChangeRequest::firstWhere('member_id', $member->id)?->new_email);
    }

    public function test_the_confirm_form_uses_the_secure_shell_for_the_logged_in_subject(): void
    {
        // The subject opening their own link while logged in gets the secure shell, matching the
        // logged-in nav/banner the Classic shell renders — so the OpenPNE 3 skin styles a coherent
        // secure_page + member-nav combination, not insecure_page + member nav.
        $member = Member::factory()->create();
        $raw = str_repeat('i', 40);
        EmailChangeRequest::create([
            'member_id' => $member->id, 'new_email' => 'new@example.com',
            'token' => hash('sha256', $raw), 'created_at' => now(),
        ]);

        $this->actingAs($member)->get('/member/config/email/confirm/'.$raw)
            ->assertOk()
            ->assertSee('class="secure_page"', false)
            ->assertSee('new@example.com');
    }

    public function test_the_confirm_form_redirects_for_an_invalid_token(): void
    {
        $this->get('/member/config/email/confirm/'.str_repeat('z', 40))->assertRedirect(route('login'));
    }

    public function test_confirming_changes_the_email_and_logs_out(): void
    {
        $member = Member::factory()->create();
        $raw = str_repeat('b', 40);
        EmailChangeRequest::create([
            'member_id' => $member->id, 'new_email' => 'changed@example.com',
            'token' => hash('sha256', $raw), 'created_at' => now(),
        ]);
        $this->actingAs($member);

        $this->post('/member/config/email/confirm/'.$raw)->assertRedirect(route('login'));

        $this->assertSame('changed@example.com', $member->fresh()->email);
        $this->assertDatabaseMissing('email_change_requests', ['member_id' => $member->id]);
        $this->get('/member/config')->assertRedirect('/login'); // logged out
    }

    public function test_confirming_rejects_an_invalid_token(): void
    {
        $this->post('/member/config/email/confirm/'.str_repeat('z', 40))->assertRedirect(route('login'));
    }

    public function test_confirming_while_logged_in_as_a_different_member_is_rejected(): void
    {
        // A different logged-in member is turned away, and the pending change, its token and their
        // session all stay intact.
        Member::factory()->create(['id' => 1]);
        $requester = Member::factory()->create();
        $other = Member::factory()->create();
        $raw = str_repeat('h', 40);
        EmailChangeRequest::create([
            'member_id' => $requester->id, 'new_email' => 'a-new@example.com',
            'token' => hash('sha256', $raw), 'created_at' => now(),
        ]);

        // Act as the other member ONCE, then chain the requests on that same session: a wrongful
        // logout/invalidate in the reject path would then surface on the final protected request,
        // rather than being masked by re-authenticating each call.
        $this->actingAs($other);

        $this->post('/member/config/email/confirm/'.$raw)->assertRedirect(route('home'));
        $this->assertNotSame('a-new@example.com', $requester->fresh()->email);
        $this->assertDatabaseHas('email_change_requests', ['member_id' => $requester->id]);

        $this->get('/member/config/email/confirm/'.$raw)->assertRedirect(route('home'));

        $this->get('/member/config')->assertOk();
    }

    public function test_confirming_rejects_an_address_claimed_since_the_request(): void
    {
        $member = Member::factory()->create();
        Member::factory()->create(['email' => 'grabbed@example.com']); // claimed after the request
        $raw = str_repeat('c', 40);
        EmailChangeRequest::create([
            'member_id' => $member->id, 'new_email' => 'grabbed@example.com',
            'token' => hash('sha256', $raw), 'created_at' => now(),
        ]);

        $this->actingAs($member)->post('/member/config/email/confirm/'.$raw)->assertRedirect(route('login'));

        $this->assertDatabaseMissing('email_change_requests', ['member_id' => $member->id]); // dead token voided
        $this->assertNotSame('grabbed@example.com', $member->fresh()->email); // unchanged
    }

    public function test_the_pc_address_category_redirects_to_email(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/member/config?category=pcAddress')
            ->assertRedirect(route('member.config', ['category' => 'email']));
    }

    public function test_a_modern_email_change_request_redirects_to_the_bare_config(): void
    {
        Notification::fake();
        config(['openpne.surface_mode' => 'modern_default']);
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/email', [
            'password' => 'password',
            'new_email' => 'new@example.com',
        ])->assertRedirect(route('member.config'));

        $this->assertDatabaseHas('email_change_requests', ['member_id' => $member->id, 'new_email' => 'new@example.com']);
    }

    public function test_confirming_an_email_change_rotates_remember_token_and_purges_other_sessions(): void
    {
        config()->set('session.driver', 'database');
        $member = Member::factory()->create(['remember_token' => 'old-remember-token']);
        DB::table('sessions')->insert([
            'id' => 'other-device-session', 'user_id' => $member->id,
            'ip_address' => '127.0.0.1', 'user_agent' => 'agent', 'payload' => 'x', 'last_activity' => 1700000000,
        ]);
        $raw = str_repeat('f', 40);
        EmailChangeRequest::create([
            'member_id' => $member->id, 'new_email' => 'confirmed@example.com',
            'token' => hash('sha256', $raw), 'created_at' => now(),
        ]);

        $this->post('/member/config/email/confirm/'.$raw)->assertRedirect(route('login'));

        $fresh = $member->fresh();
        $this->assertSame('confirmed@example.com', $fresh->email);
        $this->assertNotSame('old-remember-token', $fresh->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device-session']);
    }

    public function test_an_expired_email_change_token_is_rejected(): void
    {
        // pendingEmailChange() rejects a token past its TTL; the confirm path leaves the dead row for
        // the scheduled prune rather than burning it, and members.email is untouched.
        $member = Member::factory()->create();
        $old = $member->email;
        $raw = str_repeat('g', 40);
        $ttl = (int) config('openpne.email_change.token_ttl_minutes');
        EmailChangeRequest::create([
            'member_id' => $member->id, 'new_email' => 'expired@example.com',
            'token' => hash('sha256', $raw), 'created_at' => now()->subMinutes($ttl + 1),
        ]);

        $this->get('/member/config/email/confirm/'.$raw)->assertRedirect(route('login'));
        $this->post('/member/config/email/confirm/'.$raw)->assertRedirect(route('login'));

        $this->assertSame($old, $member->fresh()->email);
        $this->assertDatabaseHas('email_change_requests', ['member_id' => $member->id]); // left for prune
    }

    /** @return array{0: Member, 1: string} the member and the raw cancel token of a seeded pending change. */
    private function seedPendingWithCancelToken(string $rawCancel, string $newEmail = 'new@example.com'): array
    {
        $member = Member::factory()->create();
        EmailChangeRequest::create([
            'member_id' => $member->id,
            'new_email' => $newEmail,
            'token' => hash('sha256', str_repeat('c', 40)),
            'cancel_token' => hash('sha256', $rawCancel),
            'created_at' => now(),
        ]);

        return [$member, $rawCancel];
    }

    public function test_the_cancel_form_renders_and_does_not_void_on_the_get(): void
    {
        [$member, $raw] = $this->seedPendingWithCancelToken(str_repeat('a', 40));

        // Reachable without auth; the GET only renders, so a mail scanner / prefetch cannot cancel.
        $this->get('/member/config/email/cancel/'.$raw)
            ->assertOk()
            ->assertSee('id="page_member_emailChangeCancel"', false)
            ->assertSee('class="insecure_page"', false)
            ->assertSee('new@example.com')
            ->assertSee(route('member.config.email.cancel.submit', ['token' => $raw]), false);

        $this->assertDatabaseHas('email_change_requests', ['member_id' => $member->id]);
    }

    public function test_cancelling_voids_the_pending_change(): void
    {
        [$member, $raw] = $this->seedPendingWithCancelToken(str_repeat('b', 40));

        $this->post('/member/config/email/cancel/'.$raw)
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('email_change_requests', ['member_id' => $member->id]);
    }

    public function test_an_unknown_cancel_token_is_a_no_op_success(): void
    {
        // A gone/never-existed row is already not pending, so the POST is a harmless no-op success.
        $this->post('/member/config/email/cancel/'.str_repeat('z', 40))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');
        $this->get('/member/config/email/cancel/'.str_repeat('z', 40))->assertRedirect(route('login'));
    }

    public function test_an_expired_cancel_token_is_rejected(): void
    {
        $member = Member::factory()->create();
        $raw = str_repeat('e', 40);
        $ttl = (int) config('openpne.email_change.token_ttl_minutes');
        EmailChangeRequest::create([
            'member_id' => $member->id, 'new_email' => 'new@example.com',
            'token' => hash('sha256', str_repeat('c', 40)),
            'cancel_token' => hash('sha256', $raw), 'created_at' => now()->subMinutes($ttl + 1),
        ]);

        $this->post('/member/config/email/cancel/'.$raw)->assertRedirect(route('login'));

        $this->assertDatabaseHas('email_change_requests', ['member_id' => $member->id]); // left for prune
    }

    public function test_a_confirm_race_with_a_cancel_does_not_change_the_email(): void
    {
        // A cancel between the controller's load and the action's commit must leave the identifier
        // unflipped.
        $member = Member::factory()->create(['email' => 'old@example.com']);
        $pending = EmailChangeRequest::create([
            'member_id' => $member->id, 'new_email' => 'new@example.com',
            'token' => hash('sha256', str_repeat('r', 40)),
            'cancel_token' => hash('sha256', str_repeat('s', 40)), 'created_at' => now(),
        ]);

        // The controller's stale in-memory model; the row is gone by the time the action's transaction runs.
        EmailChangeRequest::whereKey($pending->getKey())->delete();

        $this->assertNull(app(ConfirmEmailChange::class)($pending));
        $this->assertSame('old@example.com', $member->fresh()->email);
    }

    public function test_a_confirm_token_does_not_work_on_the_cancel_route(): void
    {
        // The two tokens are distinct namespaces (separate columns): the confirm token, known to the
        // new-address holder, must not cancel; only the old-address cancel token does.
        $member = Member::factory()->create();
        $confirmRaw = str_repeat('h', 40);
        EmailChangeRequest::create([
            'member_id' => $member->id, 'new_email' => 'new@example.com',
            'token' => hash('sha256', $confirmRaw),
            'cancel_token' => hash('sha256', str_repeat('k', 40)), 'created_at' => now(),
        ]);

        $this->post('/member/config/email/cancel/'.$confirmRaw)->assertRedirect(route('login'));

        $this->assertDatabaseHas('email_change_requests', ['member_id' => $member->id]);
    }
}
