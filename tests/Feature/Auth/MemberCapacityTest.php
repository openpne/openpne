<?php

namespace Tests\Feature\Auth;

use App\Features\Auth\Actions\IssueRegistrationToken;
use App\Features\Auth\Actions\IssueResult;
use App\Features\Auth\CapacityState;
use App\Features\Auth\MemberCapacity;
use App\Features\Auth\RegistrationTokenSource;
use App\Filament\Pages\InviteMembers;
use App\Models\AdminUser;
use App\Models\Member;
use App\Models\RegistrationToken;
use App\Notifications\Auth\MemberCapacityRequestNotification;
use App\Notifications\Auth\RegistrationLinkNotification;
use App\Support\SnsSettingKey;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The host-set member capacity (OpenPNE 3's hosting plugin, pnejp_member_capacity): seats are
 * members plus live registration links, every issuance path stops at the last seat, and the paused
 * screen offers the request mail.
 */
class MemberCapacityTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'sufficiently-long-pw';

    private function cap(?int $limit): void
    {
        $this->setSnsSetting(SnsSettingKey::MemberCapacity, $limit);
    }

    /** A live pending registration; returns the raw token its link would carry. */
    private function liveToken(string $email = 'invited@example.com', RegistrationTokenSource $source = RegistrationTokenSource::AdminInvite): string
    {
        $raw = Str::random(40);
        RegistrationToken::create(['email' => $email, 'token' => hash('sha256', $raw), 'source' => $source->value, 'created_at' => now()]);

        return $raw;
    }

    /** Render the entry form (arms the spam-trap stamp) and let a human-plausible delay pass. */
    private function armEntry(): void
    {
        $this->get('/register');
        $this->travel(3)->seconds();
    }

    public function test_no_cap_means_no_seats_are_counted(): void
    {
        Member::factory()->count(3)->create();

        $capacity = app(MemberCapacity::class);
        $this->assertNull($capacity->limit());
        $this->assertNull($capacity->remaining());
        $this->assertFalse($capacity->isFull());
        $this->assertFalse($capacity->isFullForCompletion());
        $this->assertSame(CapacityState::Unlimited, $capacity->state());

        $this->get('/register')->assertOk()->assertSee('name="email"', false);
    }

    public function test_live_links_hold_seats_and_expired_ones_free_them(): void
    {
        $this->cap(2);
        Member::factory()->create();
        $this->liveToken();

        $capacity = app(MemberCapacity::class);
        $this->assertSame(1, $capacity->pending());
        $this->assertSame(0, $capacity->remaining());
        $this->assertTrue($capacity->isFull());
        // The seat is spoken for, but the link's own holder may still complete.
        $this->assertFalse($capacity->isFullForCompletion());

        $this->travel((int) config('openpne.registration.token_ttl_minutes') + 1)->minutes();

        $this->assertSame(0, $capacity->pending());
        $this->assertFalse($capacity->isFull());
    }

    public function test_ai_accounts_take_no_seat(): void
    {
        $this->cap(2);
        $owner = Member::factory()->create();
        Member::factory()->aiAccount($owner)->count(3)->create();

        $capacity = app(MemberCapacity::class);
        $this->assertSame(1, $capacity->members());
        $this->assertSame(1, $capacity->remaining());
        $this->assertFalse($capacity->isFull());
    }

    public function test_the_state_names_the_warning_band(): void
    {
        $capacity = app(MemberCapacity::class);
        Member::factory()->count(5)->create();

        $this->cap(20);
        $this->assertSame(CapacityState::Ok, $capacity->state());
        $this->cap(10);
        $this->assertSame(CapacityState::Low, $capacity->state());
        $this->cap(5);
        $this->assertSame(CapacityState::Full, $capacity->state());
        $this->cap(4);
        $this->assertSame(CapacityState::Over, $capacity->state());
        $this->assertSame(0, $capacity->remaining());
    }

    public function test_a_full_site_pauses_the_entry_on_both_surfaces(): void
    {
        $this->cap(1);
        Member::factory()->create();

        $this->get('/register')
            ->assertOk()
            ->assertSee('id="noRegisterBoxError"', false)
            ->assertSee(__('Registration is paused for now.'))
            ->assertSee('action="'.route('register.capacity-request').'"', false)
            ->assertDontSee('name="email"', false);

        config()->set('openpne.surface_mode', 'modern_default');
        $this->get('/register')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/registration-paused'));
    }

    public function test_a_full_site_issues_no_token_from_the_entry(): void
    {
        Notification::fake();
        $this->cap(1);
        Member::factory()->create();
        $this->armEntry();

        $this->post('/register', ['email' => 'newcomer@example.com'])->assertRedirect(route('register'));

        $this->assertDatabaseCount('registration_tokens', 0);
        Notification::assertNothingSent();
    }

    public function test_the_last_seat_fills_at_the_entry_as_the_cap_is_reached(): void
    {
        // A site that fills between the screen rendering and the submit still issues nothing.
        Notification::fake();
        $this->cap(1);
        $this->armEntry();
        Member::factory()->create();

        $this->post('/register', ['email' => 'newcomer@example.com'])->assertRedirect(route('register'));

        $this->assertDatabaseCount('registration_tokens', 0);
        Notification::assertNothingSent();
    }

    public function test_a_live_link_completes_on_the_last_seat(): void
    {
        $this->cap(1);
        $raw = $this->liveToken('invited@example.com');

        $this->assertTrue(app(MemberCapacity::class)->isFull());
        $this->get("/register/{$raw}")->assertOk()->assertSee('name="name"', false);

        $this->post("/register/{$raw}", ['name' => 'Invitee', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])
            ->assertRedirect(route('home'));

        $this->assertDatabaseHas('members', ['email' => 'invited@example.com']);
        $this->assertDatabaseCount('registration_tokens', 0);
    }

    public function test_a_live_link_cannot_complete_once_members_fill_the_cap(): void
    {
        $this->cap(1);
        $raw = $this->liveToken('invited@example.com');
        Member::factory()->create();

        $this->get("/register/{$raw}")
            ->assertOk()
            ->assertSee('id="noRegisterBoxError"', false)
            ->assertDontSee('name="name"', false);

        $this->post("/register/{$raw}", ['name' => 'Invitee', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])
            ->assertRedirect(route('register.form', ['token' => $raw]));

        $this->assertDatabaseMissing('members', ['email' => 'invited@example.com']);
        // The token outlives the refusal: the link works again once a seat frees up.
        $this->assertDatabaseCount('registration_tokens', 1);
        $this->assertGuest();
    }

    public function test_a_resend_to_an_address_holding_a_live_link_passes_a_full_site(): void
    {
        Notification::fake();
        $this->cap(1);
        Member::factory()->create();
        $this->liveToken('invited@example.com');
        $this->assertTrue(app(MemberCapacity::class)->isFull());

        $result = app(IssueRegistrationToken::class)('invited@example.com', RegistrationTokenSource::AdminInvite);

        $this->assertSame(IssueResult::Issued, $result);
        $this->assertDatabaseCount('registration_tokens', 1);
        Notification::assertSentOnDemand(RegistrationLinkNotification::class);

        $this->assertSame(IssueResult::CapacityFull, app(IssueRegistrationToken::class)('another@example.com', RegistrationTokenSource::AdminInvite));
        $this->assertDatabaseCount('registration_tokens', 1);
    }

    public function test_a_full_site_pauses_the_member_invite_on_both_surfaces(): void
    {
        Notification::fake();
        $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'invite');
        $this->cap(1);
        $inviter = Member::factory()->create();

        $this->actingAs($inviter)->get('/invite')
            ->assertOk()
            ->assertSee('id="requestRegisterURL"', false)
            ->assertSee(__('Registration is paused for now.'))
            ->assertDontSee('name="email"', false);

        $this->actingAs($inviter)->post('/invite', ['email' => 'invitee@example.com'])
            ->assertRedirect(route('member.invite'));
        $this->assertDatabaseCount('registration_tokens', 0);
        Notification::assertNothingSent();

        config()->set('openpne.surface_mode', 'modern_default');
        $this->actingAs($inviter)->get('/invite')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('member/invite')->where('paused', true));
    }

    public function test_admin_invites_fill_the_free_seats_and_report_the_rest(): void
    {
        Notification::fake();
        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
        $this->cap(3);
        Member::factory()->create();

        Livewire::test(InviteMembers::class)
            ->fillForm(['emails' => "a@example.com\nb@example.com\nc@example.com"])
            ->call('send')
            ->assertHasNoErrors()
            ->assertNotified();

        $this->assertDatabaseCount('registration_tokens', 2);
        $this->assertDatabaseMissing('registration_tokens', ['email' => 'c@example.com']);
        Notification::assertSentOnDemandTimes(RegistrationLinkNotification::class, 2);
    }

    public function test_the_admin_invite_page_shows_the_full_notice_instead_of_the_form(): void
    {
        Notification::fake();
        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
        $this->cap(1);
        Member::factory()->create();

        Livewire::test(InviteMembers::class)
            ->assertSee(__('The site is at its member capacity, so no one can be invited.'))
            ->assertDontSee(__('Send invitations'));

        $this->assertDatabaseCount('registration_tokens', 0);
        Notification::assertNothingSent();
    }

    public function test_a_guest_on_a_full_site_can_ask_the_administrator_for_a_seat(): void
    {
        Notification::fake();
        $this->setSnsSetting(SnsSettingKey::AdminMailAddress, 'ops@example.test');
        $this->cap(1);
        Member::factory()->create();

        $this->post('/register/capacity-request')
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', __('Your request has been sent to the administrator.'));

        Notification::assertSentOnDemand(
            MemberCapacityRequestNotification::class,
            fn (MemberCapacityRequestNotification $n, array $channels, AnonymousNotifiable $to): bool => ($to->routes['mail'] ?? null) === 'ops@example.test'
                && $n->host === parse_url((string) config('app.url'), PHP_URL_HOST),
        );
    }

    public function test_a_member_on_a_full_site_is_sent_home_after_asking(): void
    {
        Notification::fake();
        $this->cap(1);
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/register/capacity-request')->assertRedirect(route('home'));

        Notification::assertSentOnDemand(MemberCapacityRequestNotification::class);
    }

    public function test_the_request_is_404_unless_the_site_is_full_and_open_to_registration(): void
    {
        Notification::fake();

        $this->cap(2);
        Member::factory()->create();
        $this->post('/register/capacity-request')->assertNotFound();

        $this->cap(1);
        $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'closed');
        $this->post('/register/capacity-request')->assertNotFound();

        Notification::assertNothingSent();
    }

    public function test_the_request_is_rate_limited_per_address(): void
    {
        Notification::fake();
        $this->cap(1);
        Member::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->post('/register/capacity-request')->assertRedirect(route('login'));
        }

        $this->post('/register/capacity-request')->assertStatus(429);
    }

    public function test_the_request_is_rate_limited_site_wide_across_addresses(): void
    {
        Notification::fake();
        $this->cap(1);
        Member::factory()->create();

        for ($i = 0; $i < 20; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.($i + 1)])
                ->post('/register/capacity-request')->assertRedirect(route('login'));
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->post('/register/capacity-request')->assertStatus(429);
    }

    public function test_the_command_takes_effect_at_once_on_a_warm_cache(): void
    {
        Member::factory()->create();
        $this->assertNull(app(MemberCapacity::class)->limit()); // warms the settings cache

        $this->artisan('openpne:capacity', ['which' => 'member', 'value' => '1'])->assertSuccessful();

        $this->get('/register')->assertOk()->assertSee('id="noRegisterBoxError"', false);
    }
}
