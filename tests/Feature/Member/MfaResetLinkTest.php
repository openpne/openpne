<?php

declare(strict_types=1);

namespace Tests\Feature\Member;

use App\Features\Member\Actions\ConsumeMfaReset;
use App\Features\Member\Actions\ForceDisableMemberMfa;
use App\Features\Member\Actions\RequestMfaReset;
use App\Models\EmailChangeRequest;
use App\Models\Member;
use App\Models\MfaResetRequest;
use App\Notifications\Member\MfaDisabledNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use RuntimeException;
use Tests\Concerns\CapturesSecurityLog;
use Tests\TestCase;

/**
 * The admin-issued two-factor reset link flow: an admin mails the member's registered address a link; the
 * locked-out member (a guest) opens it and clears their factor with their account password. See
 * docs/internals/security.md for the boundary invariant and the invalidation contract.
 */
class MfaResetLinkTest extends TestCase
{
    use CapturesSecurityLog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->captureSecurityLog();
    }

    // --- GET render ---------------------------------------------------------------------------------

    public function test_get_renders_the_classic_form_for_a_guest(): void
    {
        $member = $this->memberWithLiveFactor();
        $raw = $this->seedLink($member);

        $this->get("/member/mfa/reset/{$raw}")
            ->assertOk()
            ->assertSee('id="page_member_mfaReset"', false)
            ->assertSee('class="insecure_page"', false)
            ->assertSee(route('member.mfa.reset.submit', ['token' => $raw]), false)
            // The token page never surfaces the account's address or name.
            ->assertDontSee($member->email)
            ->assertDontSee($member->name);
    }

    public function test_get_renders_the_modern_form_under_modern_only(): void
    {
        config()->set('openpne.surface_mode', 'modern_only');
        $member = $this->memberWithLiveFactor();
        $raw = $this->seedLink($member);

        $this->get("/member/mfa/reset/{$raw}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('auth/mfa-reset')
                ->where('token', $raw)
                // Only the token is passed — no email/name leak onto the token page.
                ->missing('newEmail')
                ->missing('email'));
    }

    public function test_get_carries_the_no_referrer_header(): void
    {
        $member = $this->memberWithLiveFactor();
        $raw = $this->seedLink($member);

        // The URL carries a secret; NoReferrer keeps it out of the Referer header on click-out.
        $this->get("/member/mfa/reset/{$raw}")->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_get_redirects_for_an_invalid_token(): void
    {
        $this->get('/member/mfa/reset/'.str_repeat('z', 40))->assertRedirect(route('login'));
    }

    public function test_get_redirects_for_an_expired_token(): void
    {
        $member = $this->memberWithLiveFactor();
        $raw = $this->seedLink($member, minutesAgo: (int) config('openpne.mfa_reset.token_ttl_minutes') + 1);

        $this->get("/member/mfa/reset/{$raw}")->assertRedirect(route('login'));
        // Expired rows are left for the scheduled prune, not burned on read.
        $this->assertDatabaseHas('mfa_reset_requests', ['member_id' => $member->getKey()]);
    }

    public function test_get_with_the_factor_already_off_is_read_only(): void
    {
        // The factor is off but a row lingers (a contrived race): GET must not mutate — no burn on read.
        $member = Member::factory()->create();
        $raw = $this->seedLink($member);

        $this->get("/member/mfa/reset/{$raw}")->assertRedirect(route('login'));
        $this->assertDatabaseHas('mfa_reset_requests', ['member_id' => $member->getKey()]);
    }

    public function test_factor_off_get_leaves_every_scrap_of_state_untouched(): void
    {
        Notification::fake();
        config()->set('session.driver', 'database');
        $member = Member::factory()->create(['remember_token' => 'keep-me']);
        $raw = $this->seedLink($member);
        $rowBefore = MfaResetRequest::where('member_id', $member->getKey())->sole()->getAttributes();

        $this->get("/member/mfa/reset/{$raw}")->assertRedirect(route('login'));

        $this->assertSame($rowBefore, MfaResetRequest::where('member_id', $member->getKey())->sole()->getAttributes());
        $fresh = $member->fresh();
        $this->assertNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_confirmed_at);
        $this->assertSame('keep-me', $fresh->remember_token);
        $this->assertCount(0, $this->securityRecords('mfa.disabled'));
        Notification::assertNothingSent();
    }

    // --- POST reset ---------------------------------------------------------------------------------

    public function test_post_resets_the_factor_and_revokes_sessions(): void
    {
        Notification::fake();
        config()->set('session.driver', 'database');
        $member = $this->memberWithLiveFactor();
        $member->forceFill(['remember_token' => Str::random(60)])->save();
        $before = $member->fresh()->remember_token;
        $raw = $this->seedLink($member);

        DB::table('sessions')->insert([
            'id' => 'device', 'user_id' => $member->getKey(),
            'payload' => base64_encode('{}'), 'last_activity' => time(),
        ]);

        $this->post("/member/mfa/reset/{$raw}", ['password' => 'password'])
            ->assertRedirect(route('login'));

        $fresh = $member->fresh();
        $this->assertNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_recovery_codes);
        $this->assertNull($fresh->two_factor_confirmed_at);
        $this->assertNotSame($before, $fresh->remember_token); // rotated
        $this->assertDatabaseMissing('sessions', ['id' => 'device']); // all revoked
        $this->assertDatabaseMissing('mfa_reset_requests', ['member_id' => $member->getKey()]); // burned

        // Logged (via reset_link, and WITHOUT admin_username — the member acted, not the admin) then alerted.
        $context = $this->assertOneSecurityEvent('mfa.disabled');
        $this->assertSame('reset_link', $context['via']);
        $this->assertArrayNotHasKey('admin_username', $context);
        Notification::assertSentTo($member, MfaDisabledNotification::class);
    }

    public function test_post_with_a_wrong_password_errors_and_spends_nothing(): void
    {
        Notification::fake();
        $member = $this->memberWithLiveFactor();
        $raw = $this->seedLink($member);

        $this->from("/member/mfa/reset/{$raw}")
            ->post("/member/mfa/reset/{$raw}", ['password' => 'wrong-password'])
            ->assertRedirect("/member/mfa/reset/{$raw}")
            ->assertSessionHasErrors('password');

        // Nothing spent: the factor is intact, the token survives, and no disable was logged or alerted.
        $this->assertTrue($member->fresh()->hasEnabledTwoFactorAuthentication());
        $this->assertDatabaseHas('mfa_reset_requests', ['member_id' => $member->getKey()]);
        $this->assertCount(0, $this->securityRecords('mfa.disabled'));
        Notification::assertNothingSent();
    }

    public function test_wrong_password_throttling_is_per_token_not_per_ip(): void
    {
        $memberA = $this->memberWithLiveFactor();
        $rawA = $this->seedLink($memberA);
        $memberB = $this->memberWithLiveFactor();
        $rawB = $this->seedLink($memberB);

        // The per-token mfa-reset limiter is 5/min; the 6th guess against token A is 429'd.
        for ($i = 0; $i < 5; $i++) {
            $this->post("/member/mfa/reset/{$rawA}", ['password' => 'wrong-password'])->assertRedirect();
        }
        $this->post("/member/mfa/reset/{$rawA}", ['password' => 'wrong-password'])->assertStatus(429);

        // From the SAME IP, token B's first guess still passes: the limiter keys on the hashed token, so a
        // per-IP implementation (which would 429 this too) is ruled out.
        $this->post("/member/mfa/reset/{$rawB}", ['password' => 'wrong-password'])
            ->assertStatus(302)
            ->assertSessionHasErrors('password');
    }

    public function test_a_consumed_token_is_dead_on_reuse(): void
    {
        $member = $this->memberWithLiveFactor();
        $raw = $this->seedLink($member);

        $this->post("/member/mfa/reset/{$raw}", ['password' => 'password'])->assertRedirect(route('login'));
        // Reuse: the row is gone, so it is a dead link.
        $this->post("/member/mfa/reset/{$raw}", ['password' => 'password'])->assertRedirect(route('login'));
    }

    public function test_post_with_the_factor_already_off_burns_the_token_only(): void
    {
        Notification::fake();
        // The factor was cleared elsewhere after the form loaded; a lingering row is burned, no reset happens.
        $member = Member::factory()->create();
        $raw = $this->seedLink($member);

        $this->post("/member/mfa/reset/{$raw}", ['password' => 'password'])->assertRedirect(route('login'));

        $this->assertDatabaseMissing('mfa_reset_requests', ['member_id' => $member->getKey()]); // burned
        $this->assertCount(0, $this->securityRecords('mfa.disabled')); // nothing disabled
        Notification::assertNothingSent();
    }

    public function test_a_different_logged_in_member_is_turned_away_get_and_post(): void
    {
        $subject = $this->memberWithLiveFactor();
        $other = Member::factory()->create();
        $raw = $this->seedLink($subject);

        $this->actingAs($other);

        // POST is rejected without consuming (a valid password is supplied so the reject, not validation, fires).
        $this->post("/member/mfa/reset/{$raw}", ['password' => 'password'])->assertRedirect(route('home'));
        $this->assertTrue($subject->fresh()->hasEnabledTwoFactorAuthentication());
        $this->assertDatabaseHas('mfa_reset_requests', ['member_id' => $subject->getKey()]);

        // GET is likewise turned away; the other member keeps their session.
        $this->get("/member/mfa/reset/{$raw}")->assertRedirect(route('home'));
        $this->get('/member/config')->assertOk();
    }

    public function test_a_different_member_with_an_empty_password_still_hits_the_home_reject(): void
    {
        // Pins the reorder: the different-member reject runs BEFORE password validation, so an empty
        // password lands on the home reject — not a validation redirect that would leak the link is live.
        $subject = $this->memberWithLiveFactor();
        $other = Member::factory()->create();
        $raw = $this->seedLink($subject);

        $this->actingAs($other)
            ->post("/member/mfa/reset/{$raw}", ['password' => ''])
            ->assertRedirect(route('home'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('mfa_reset_requests', ['member_id' => $subject->getKey()]);
    }

    public function test_the_subject_consuming_their_own_session_gets_signed_out(): void
    {
        $member = $this->memberWithLiveFactor();
        $raw = $this->seedLink($member);

        // The subject opened the link in their own logged-in session: the reset succeeds and ends it.
        $this->actingAs($member)
            ->post("/member/mfa/reset/{$raw}", ['password' => 'password'])
            ->assertRedirect(route('login'));

        $this->assertFalse($member->fresh()->hasEnabledTwoFactorAuthentication());
        $this->get('/member/config')->assertRedirect('/login'); // logged out
    }

    public function test_a_real_password_change_keeps_the_link_and_rebinds_the_proof(): void
    {
        // The link binds to member_id, and the proof it demands is the CURRENT password: a password change
        // does not void it (unlike an email change), but the old password no longer opens it. Driven as the
        // real in-session member-config password change, not a forceFill.
        $member = $this->memberWithLiveFactor();
        $raw = $this->seedLink($member);

        $this->actingAs($member)->post('/member/config/password', [
            'current_password' => 'password',
            'password' => 'new-secret-pass',
            'password_confirmation' => 'new-secret-pass',
        ])->assertSessionHasNoErrors();

        // The pending link survives the password change.
        $this->assertDatabaseHas('mfa_reset_requests', ['member_id' => $member->getKey()]);

        // The old password no longer opens it, and spends nothing.
        $this->post("/member/mfa/reset/{$raw}", ['password' => 'password'])->assertSessionHasErrors('password');
        $this->assertTrue($member->fresh()->hasEnabledTwoFactorAuthentication());

        // The new password consumes it.
        $this->post("/member/mfa/reset/{$raw}", ['password' => 'new-secret-pass'])->assertRedirect(route('login'));
        $this->assertFalse($member->fresh()->hasEnabledTwoFactorAuthentication());
    }

    // --- invalidation contract ----------------------------------------------------------------------

    public function test_disabling_then_reenabling_within_the_ttl_kills_the_old_link(): void
    {
        // Invalidation contract (a): send → disable → re-enable a new factor within the TTL must not
        // leave the old link live against the new factor.
        $member = $this->memberWithLiveFactor();
        $raw = $this->seedLink($member);

        // The member (or CLI) disables the factor — the row is dropped — then enrolls a fresh factor.
        app(ForceDisableMemberMfa::class)($member);
        $this->assertDatabaseMissing('mfa_reset_requests', ['member_id' => $member->getKey()]);
        $this->giveLiveFactor($member->fresh());

        // The old link is dead.
        $this->post("/member/mfa/reset/{$raw}", ['password' => 'password'])->assertRedirect(route('login'));
        $this->assertTrue($member->fresh()->hasEnabledTwoFactorAuthentication()); // the new factor survives
    }

    public function test_confirming_an_email_change_kills_the_old_link(): void
    {
        // Invalidation contract (b): the address is the proof channel, so changing it voids a pending
        // reset.
        $member = $this->memberWithLiveFactor();
        $raw = $this->seedLink($member);

        $confirmRaw = str_repeat('e', 40);
        EmailChangeRequest::create([
            'member_id' => $member->getKey(), 'new_email' => 'moved@example.com',
            'token' => hash('sha256', $confirmRaw), 'created_at' => now(),
        ]);
        $this->post('/member/config/email/confirm/'.$confirmRaw)->assertRedirect(route('login'));

        $this->assertDatabaseMissing('mfa_reset_requests', ['member_id' => $member->getKey()]);
        $this->post("/member/mfa/reset/{$raw}", ['password' => 'password'])->assertRedirect(route('login'));
        $this->assertTrue($member->fresh()->hasEnabledTwoFactorAuthentication()); // factor untouched
    }

    // --- action-level guards (TOCTOU) ---------------------------------------------------------------

    public function test_request_mfa_reset_throws_and_mints_nothing_on_a_stale_live_factor(): void
    {
        Notification::fake();
        $member = $this->memberWithLiveFactor();

        // The factor was disabled after the Filament visibility snapshot; the stale in-memory model still
        // reports it live. The action re-checks under the lock and refuses (caller bug), minting nothing.
        Member::whereKey($member->getKey())->update(['two_factor_secret' => null, 'two_factor_confirmed_at' => null]);

        try {
            app(RequestMfaReset::class)($member);
            $this->fail('expected a RuntimeException for a member with no live factor');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertDatabaseMissing('mfa_reset_requests', ['member_id' => $member->getKey()]);
        $this->assertCount(0, $this->securityRecords('mfa.reset_link_sent'));
        Notification::assertNothingSent();
    }

    public function test_request_mfa_reset_throws_when_the_address_was_cleared(): void
    {
        Notification::fake();
        $member = $this->memberWithLiveFactor();
        Member::whereKey($member->getKey())->update(['email' => null]);

        try {
            app(RequestMfaReset::class)($member);
            $this->fail('expected a RuntimeException for a member with no registered address');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertDatabaseMissing('mfa_reset_requests', ['member_id' => $member->getKey()]);
        Notification::assertNothingSent();
    }

    public function test_consume_reports_invalid_for_a_replaced_row(): void
    {
        // The controller looked up token A, but a re-send replaced the row (token B) before the lock: the
        // in-transaction re-fetch by (member_id + hash) misses, so nothing is disabled.
        $member = $this->memberWithLiveFactor();
        $this->seedLink($member); // token B is what is stored now
        $tokenA = str_repeat('9', 40);

        $result = app(ConsumeMfaReset::class)($member->getKey(), $tokenA, 'password');

        $this->assertTrue($result->isInvalid());
        $this->assertNull($result->member);
        $this->assertTrue($member->fresh()->hasEnabledTwoFactorAuthentication());
        $this->assertDatabaseHas('mfa_reset_requests', ['member_id' => $member->getKey()]); // the live row survives
    }

    public function test_consume_reports_invalid_for_a_withdrawn_member(): void
    {
        // The member withdrew between the controller's unlocked lookup and the action's lock: the in-tx
        // Member lookup returns null, so it is a dead link — invalid(), not a firstOrFail 404.
        $member = $this->memberWithLiveFactor();
        $raw = $this->seedLink($member);
        $memberId = $member->getKey();
        $member->delete(); // cascade drops the row, but the controller still holds the stale id

        $result = app(ConsumeMfaReset::class)($memberId, $raw, 'password');

        $this->assertTrue($result->isInvalid());
        $this->assertNull($result->member);
    }

    public function test_consume_rechecks_expiry_inside_the_transaction(): void
    {
        // Simulates the controller's lookup having crossed the expiry boundary before the lock: the in-tx
        // TTL re-check declines and leaves the row for the prune.
        $member = $this->memberWithLiveFactor();
        $raw = $this->seedLink($member, minutesAgo: (int) config('openpne.mfa_reset.token_ttl_minutes') + 1);

        $result = app(ConsumeMfaReset::class)($member->getKey(), $raw, 'password');

        $this->assertTrue($result->isInvalid());
        $this->assertTrue($member->fresh()->hasEnabledTwoFactorAuthentication());
        $this->assertDatabaseHas('mfa_reset_requests', ['member_id' => $member->getKey()]);
    }

    public function test_consume_reports_already_off_and_burns_the_row(): void
    {
        // The factor was cleared elsewhere; consuming reports alreadyOff and burns the spent link.
        $member = Member::factory()->create();
        $raw = $this->seedLink($member);

        $result = app(ConsumeMfaReset::class)($member->getKey(), $raw, 'password');

        $this->assertTrue($result->isAlreadyOff());
        $this->assertNull($result->member);
        $this->assertDatabaseMissing('mfa_reset_requests', ['member_id' => $member->getKey()]);
    }

    public function test_consume_throws_on_a_wrong_password_without_spending_the_token(): void
    {
        $member = $this->memberWithLiveFactor();
        $raw = $this->seedLink($member);

        try {
            app(ConsumeMfaReset::class)($member->getKey(), $raw, 'wrong-password');
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('password', $e->errors());
        }

        $this->assertTrue($member->fresh()->hasEnabledTwoFactorAuthentication());
        $this->assertDatabaseHas('mfa_reset_requests', ['member_id' => $member->getKey()]);
    }

    // --- prune --------------------------------------------------------------------------------------

    public function test_prune_removes_expired_links_and_keeps_live_ones(): void
    {
        $expiredMember = $this->memberWithLiveFactor();
        $this->seedLink($expiredMember, minutesAgo: (int) config('openpne.mfa_reset.token_ttl_minutes') + 1);

        $liveMember = $this->memberWithLiveFactor();
        $this->seedLink($liveMember);

        $this->artisan('model:prune', ['--model' => [MfaResetRequest::class]])->assertSuccessful();

        $this->assertDatabaseMissing('mfa_reset_requests', ['member_id' => $expiredMember->getKey()]);
        $this->assertDatabaseHas('mfa_reset_requests', ['member_id' => $liveMember->getKey()]);
    }

    public function test_withdrawal_cascade_removes_the_pending_link(): void
    {
        $member = $this->memberWithLiveFactor();
        $this->seedLink($member);

        $member->delete();

        $this->assertDatabaseMissing('mfa_reset_requests', ['member_id' => $member->getKey()]);
    }

    // --- helpers ------------------------------------------------------------------------------------

    private function memberWithLiveFactor(): Member
    {
        $member = Member::factory()->create();
        $this->giveLiveFactor($member);

        return $member;
    }

    private function giveLiveFactor(Member $member): void
    {
        app(EnableTwoFactorAuthentication::class)($member, force: true);
        $member->forceFill(['two_factor_confirmed_at' => now()])->save();
    }

    /** Seed a pending reset row and return the raw token the link would carry. */
    private function seedLink(Member $member, int $minutesAgo = 0): string
    {
        $raw = Str::random(40);
        MfaResetRequest::create([
            'member_id' => $member->getKey(),
            'token' => hash('sha256', $raw),
            'created_at' => now()->subMinutes($minutesAgo),
        ]);

        return $raw;
    }
}
