<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Features\Member\Actions\MfaResetUnavailable;
use App\Features\Member\Actions\RequestMfaReset;
use App\Filament\Resources\Members\MemberResource;
use App\Filament\Resources\Members\Pages\ListMembers;
use App\Models\AdminUser;
use App\Models\Diary;
use App\Models\Member;
use App\Models\MfaResetRequest;
use App\Notifications\Member\MfaResetLinkNotification;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\CapturesSecurityLog;
use Tests\TestCase;

class MemberResourceTest extends TestCase
{
    use CapturesSecurityLog;
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->admin = AdminUser::factory()->create();
        $this->actingAs($this->admin, 'admin');
        $this->captureSecurityLog();

        // Reserve id 1 as the un-withdrawable primary member so factory subjects below get id >= 2.
        Member::factory()->create(['id' => 1]);
    }

    public function test_list_page_renders_members(): void
    {
        $members = Member::factory()->count(2)->create();

        Livewire::test(ListMembers::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords($members);
    }

    public function test_search_by_name_and_email(): void
    {
        $match = Member::factory()->create(['name' => 'Findme', 'email' => 'findme@example.test']);
        $other = Member::factory()->create(['name' => 'Unrelated', 'email' => 'nope@example.test']);

        Livewire::test(ListMembers::class)
            ->searchTable('Findme')
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);

        Livewire::test(ListMembers::class)
            ->searchTable('findme@example.test')
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_ban_rejects_login(): void
    {
        $member = Member::factory()->create(['is_login_rejected' => false]);

        Livewire::test(ListMembers::class)
            ->callAction(TestAction::make('ban')->table($member));

        $member->refresh();
        $this->assertTrue($member->is_login_rejected);

        $context = $this->assertOneSecurityEvent('member.banned');
        $this->assertSame((string) $member->getKey(), $context['member_id']);
        $this->assertSame($this->admin->username, $context['admin_username']);
    }

    public function test_ban_revokes_live_sessions_and_remember_tokens(): void
    {
        // The freeze flag only blocks the NEXT login; the ban must also end what already
        // exists — server-side sessions and remember-me cookies — immediately.
        config(['session.driver' => 'database']);
        $member = Member::factory()->create(['is_login_rejected' => false]);
        $member->forceFill(['remember_token' => Str::random(60)])->save();
        $before = $member->remember_token;

        DB::table('sessions')->insert([
            'id' => 'member-device', 'user_id' => $member->getKey(),
            'payload' => base64_encode('{}'), 'last_activity' => time(),
        ]);
        // The banning operator's own panel session lives in admin_sessions; even with a
        // colliding id it must survive a member revocation.
        DB::table('admin_sessions')->insert([
            'id' => 'operator-device', 'user_id' => $member->getKey(),
            'payload' => base64_encode('{}'), 'last_activity' => time(),
        ]);

        Livewire::test(ListMembers::class)
            ->callAction(TestAction::make('ban')->table($member));

        $this->assertDatabaseMissing('sessions', ['id' => 'member-device']);
        $this->assertDatabaseHas('admin_sessions', ['id' => 'operator-device']);
        $this->assertNotSame($before, $member->fresh()->remember_token);
    }

    public function test_unban_allows_login(): void
    {
        $member = Member::factory()->create(['is_login_rejected' => true]);

        Livewire::test(ListMembers::class)
            ->callAction(TestAction::make('unban')->table($member));

        $member->refresh();
        $this->assertFalse($member->is_login_rejected);
    }

    public function test_delete_withdraws_the_member_and_owned_content(): void
    {
        $member = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $member->getKey()]);

        Livewire::test(ListMembers::class)
            ->callAction(TestAction::make('delete')->table($member))
            ->assertNotified(__('filament-actions::delete.single.notifications.deleted.title'));

        $this->assertModelMissing($member);
        $this->assertModelMissing($diary);
    }

    public function test_primary_member_cannot_be_withdrawn(): void
    {
        $primary = Member::findOrFail(1);

        $this->assertFalse(MemberResource::canDelete($primary));

        // Neither withdrawal nor login-freeze is offered for the primary member (lockout guard).
        Livewire::test(ListMembers::class)
            ->assertActionHidden(TestAction::make('delete')->table($primary))
            ->assertActionHidden(TestAction::make('ban')->table($primary));
    }

    public function test_send_mfa_reset_is_hidden_without_a_live_factor(): void
    {
        // No factor at all, and a pending (unconfirmed) set-up: neither is a live factor, so there is
        // nothing to reset — the action stays hidden.
        $none = Member::factory()->create();

        $pending = Member::factory()->create();
        app(EnableTwoFactorAuthentication::class)($pending, force: true); // secret written, never confirmed

        // A live factor but no registered address (members.email is nullable): nowhere to send.
        $noEmail = $this->memberWithLiveFactor();
        $noEmail->forceFill(['email' => null])->save();

        Livewire::test(ListMembers::class)
            ->assertActionHidden(TestAction::make('sendMfaReset')->table($none))
            ->assertActionHidden(TestAction::make('sendMfaReset')->table($pending))
            ->assertActionHidden(TestAction::make('sendMfaReset')->table($noEmail));
    }

    public function test_send_mfa_reset_is_visible_for_a_live_factor_regardless_of_primary_or_ban(): void
    {
        // Recovery is orthogonal to takeover risk and to moderation: the action offers no takeover ability
        // (member mailbox + member password), so it is visible even for the primary member and a banned one.
        $primary = Member::findOrFail(1);
        $primary->forceFill(['email' => 'primary@example.test'])->save();
        $this->giveLiveFactor($primary);

        $banned = $this->memberWithLiveFactor();
        $banned->forceFill(['is_login_rejected' => true])->save();

        Livewire::test(ListMembers::class)
            ->assertActionVisible(TestAction::make('sendMfaReset')->table($primary))
            ->assertActionVisible(TestAction::make('sendMfaReset')->table($banned));
    }

    public function test_send_mfa_reset_mails_a_link_and_logs_the_admin(): void
    {
        Notification::fake();
        $member = $this->memberWithLiveFactor();

        Livewire::test(ListMembers::class)
            ->callAction(TestAction::make('sendMfaReset')->table($member))
            ->assertNotified();

        // Exactly one pending row, the token stored as a 64-hex SHA-256 hash (never the raw token).
        $rows = MfaResetRequest::where('member_id', $member->getKey())->get();
        $this->assertCount(1, $rows);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $rows->first()->token);

        // Logged before the fallible send, carrying the acting admin's username; the token is never logged.
        $context = $this->assertOneSecurityEvent('mfa.reset_link_sent');
        $this->assertSame((string) $member->getKey(), $context['member_id']);
        $this->assertSame($this->admin->username, $context['admin_username']);
        $this->assertArrayNotHasKey('token', $context);

        // On-demand mail pinned to the member's registered address (never the Member notifiable).
        Notification::assertSentOnDemand(
            MfaResetLinkNotification::class,
            fn ($n, $channels, $notifiable): bool => ($notifiable->routes['mail'] ?? null) === $member->email,
        );
        Notification::assertSentOnDemandTimes(MfaResetLinkNotification::class, 1);
    }

    public function test_resending_replaces_the_token_in_place(): void
    {
        Notification::fake();
        $member = $this->memberWithLiveFactor();

        Livewire::test(ListMembers::class)
            ->callAction(TestAction::make('sendMfaReset')->table($member));
        $first = MfaResetRequest::where('member_id', $member->getKey())->sole()->token;

        Livewire::test(ListMembers::class)
            ->callAction(TestAction::make('sendMfaReset')->table($member));
        $rows = MfaResetRequest::where('member_id', $member->getKey())->get();

        // Still one row (member_id is unique); the token was refreshed, so the earlier link is dead.
        $this->assertCount(1, $rows);
        $this->assertNotSame($first, $rows->first()->token);
    }

    public function test_send_mfa_reset_hides_and_does_nothing_once_the_factor_is_disabled(): void
    {
        Notification::fake();
        $member = $this->memberWithLiveFactor();

        // Disabled after the row rendered (another route / the CLI): the fresh record no longer has a live
        // factor, so the action is hidden and nothing is minted, mailed, or logged.
        $member->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null])->save();

        Livewire::test(ListMembers::class)
            ->assertActionHidden(TestAction::make('sendMfaReset')->table($member));

        $this->assertDatabaseMissing('mfa_reset_requests', ['member_id' => $member->getKey()]);
        Notification::assertNothingSent();
        $this->assertCount(0, $this->securityRecords('mfa.reset_link_sent'));
    }

    public function test_send_mfa_reset_degrades_gracefully_when_the_action_races(): void
    {
        Notification::fake();
        $member = $this->memberWithLiveFactor();

        // Filament re-checks visibility on a mounted action, so a disable-then-confirm sequence would
        // silently hide it; the stand-in RequestMfaReset throws as the locked recheck would, reaching
        // the action body deterministically.
        $this->app->bind(RequestMfaReset::class, fn () => new class extends RequestMfaReset
        {
            public function __invoke(Member $member): void
            {
                throw new MfaResetUnavailable('factor invalidated between before() and the lock recheck');
            }
        });

        Livewire::test(ListMembers::class)
            ->callAction(TestAction::make('sendMfaReset')->table($member))
            ->assertNotified(__('Two-factor authentication is no longer active for this member'));

        $this->assertDatabaseMissing('mfa_reset_requests', ['member_id' => $member->getKey()]);
        Notification::assertNothingSent();
        $this->assertCount(0, $this->securityRecords('mfa.reset_link_sent'));
    }

    public function test_an_unexpected_send_failure_is_not_masked_as_the_race(): void
    {
        Notification::fake();
        $member = $this->memberWithLiveFactor();

        // Only the precondition failure (MfaResetUnavailable) is the benign race; anything else the
        // Action throws — a dead queue, a logging fault — must bubble, not hide behind the stale-factor
        // warning where monitoring would never see it.
        $this->app->bind(RequestMfaReset::class, fn () => new class extends RequestMfaReset
        {
            public function __invoke(Member $member): void
            {
                throw new RuntimeException('queue exploded');
            }
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('queue exploded');

        Livewire::test(ListMembers::class)
            ->callAction(TestAction::make('sendMfaReset')->table($member));
    }

    public function test_two_factor_column_reflects_a_live_factor(): void
    {
        $live = $this->memberWithLiveFactor();
        $off = Member::factory()->create();

        Livewire::test(ListMembers::class)
            ->assertTableColumnStateSet('two_factor', true, $live)
            ->assertTableColumnStateSet('two_factor', false, $off);
    }

    /** A member with a confirmed (live) two-factor factor and a registered address. */
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
}
