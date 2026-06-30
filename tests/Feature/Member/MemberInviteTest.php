<?php

namespace Tests\Feature\Member;

use App\Features\Auth\RegistrationTokenSource;
use App\Models\Member;
use App\Models\RegistrationToken;
use App\Notifications\Auth\RegistrationLinkNotification;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Member invitation (OpenPNE 3 member/invite): a logged-in member invites an address, which issues a
 * member-invite registration token and mails the link. The entry is gated to modes that allow member
 * invites, and completing an invited registration auto-friends the inviter.
 */
class MemberInviteTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'sufficiently-long-pw';

    /** @return array<string, string> */
    private function validForm(): array
    {
        return ['name' => 'Invitee', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD];
    }

    public function test_the_form_is_shown_to_a_member_when_member_invites_are_allowed(): void
    {
        $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'invite');

        $this->actingAs(Member::factory()->create())
            ->get('/invite')
            ->assertOk()
            ->assertSee('id="page_member_invite"', false)
            ->assertSee('name="email"', false);
    }

    public function test_closed_mode_404s_the_member_invite_form(): void
    {
        $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'closed');

        $this->actingAs(Member::factory()->create())->get('/invite')->assertNotFound();
        $this->actingAs(Member::factory()->create())
            ->post('/invite', ['email' => 'invitee@example.com'])
            ->assertNotFound();
    }

    public function test_admin_only_mode_404s_the_member_invite_form(): void
    {
        // admin_only allows admin invites but not member invites.
        $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'admin_only');

        $this->actingAs(Member::factory()->create())->get('/invite')->assertNotFound();
        $this->actingAs(Member::factory()->create())
            ->post('/invite', ['email' => 'invitee@example.com'])
            ->assertNotFound();
    }

    public function test_a_guest_cannot_reach_the_member_invite_form(): void
    {
        $this->get('/invite')->assertRedirect(route('login'));
    }

    public function test_a_member_invite_issues_a_token_tagged_with_its_inviter_and_mails_the_link(): void
    {
        Notification::fake();
        $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'invite');
        $inviter = Member::factory()->create(['name' => 'Alice']);

        $this->actingAs($inviter)
            ->post('/invite', ['email' => 'Invitee@Example.com', 'message' => 'join us'])
            ->assertRedirect(route('member.invite'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('registration_tokens', [
            'email' => 'invitee@example.com', // normalized
            'source' => RegistrationTokenSource::MemberInvite->value,
            'inviter_id' => $inviter->getKey(),
        ]);

        Notification::assertSentOnDemand(
            RegistrationLinkNotification::class,
            function (RegistrationLinkNotification $notification, array $channels, object $notifiable) use ($inviter): bool {
                return ($notifiable->routes['mail'] ?? null) === 'invitee@example.com'
                    && $notification->source === RegistrationTokenSource::MemberInvite
                    && $notification->inviterName === $inviter->name
                    && $notification->message === 'join us';
            }
        );
    }

    public function test_inviting_an_existing_member_sends_nothing_and_says_so(): void
    {
        Notification::fake();
        $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'invite');
        Member::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs(Member::factory()->create())
            ->post('/invite', ['email' => 'taken@example.com'])
            ->assertRedirect(route('member.invite'))
            ->assertSessionHas('status', __('That address already has an account, so no invitation was sent.'));

        $this->assertDatabaseMissing('registration_tokens', ['email' => 'taken@example.com']);
        Notification::assertNothingSent();
    }

    public function test_completing_a_member_invite_auto_friends_the_inviter(): void
    {
        $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'invite');
        $inviter = Member::factory()->create();
        $raw = $this->issueMemberInvite('invitee@example.com', $inviter);

        $this->post("/register/{$raw}", $this->validForm())->assertRedirect('/');

        $invitee = Member::where('email', 'invitee@example.com')->firstOrFail();
        $this->assertDatabaseHas('friendships', ['member_id' => $inviter->getKey(), 'friend_id' => $invitee->getKey()]);
        $this->assertDatabaseHas('friendships', ['member_id' => $invitee->getKey(), 'friend_id' => $inviter->getKey()]);
    }

    public function test_a_deleted_inviter_drops_the_friendship_but_not_the_registration(): void
    {
        $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'invite');
        $inviter = Member::factory()->create();
        $raw = $this->issueMemberInvite('invitee@example.com', $inviter);
        $inviter->delete(); // FK nulls inviter_id; source stays member_invite

        $this->post("/register/{$raw}", $this->validForm())->assertRedirect('/');

        $this->assertDatabaseHas('members', ['email' => 'invitee@example.com']);
        $this->assertDatabaseCount('friendships', 0);
    }

    public function test_the_invite_email_does_not_turn_member_text_into_live_links(): void
    {
        // The inviter's display name and personal note render as literal text, never Markdown/HTML, so a
        // member cannot slip a live link, remote image, or script into the branded invite mail.
        $mail = (new RegistrationLinkNotification(
            Str::random(40),
            'en',
            RegistrationTokenSource::MemberInvite,
            '[evilname](http://evil.test)',
            'see [here](http://evil.test) ![x](http://evil.test/p.png) <script>alert(1)</script>',
        ))->toMail(new AnonymousNotifiable);

        $html = $this->renderMailHtml($mail);

        $this->assertStringContainsString('evilname', $html);                     // name kept as text
        $this->assertStringNotContainsString('<a href="http://evil.test', $html); // no live link
        $this->assertStringNotContainsString('<img', $html);                      // no remote image
        $this->assertStringNotContainsString('<script>', $html);                  // script escaped
    }

    /** Create a live member-invite token for an address and return the raw token its link carries. */
    private function issueMemberInvite(string $email, Member $inviter): string
    {
        $raw = Str::random(40);
        RegistrationToken::create([
            'email' => $email,
            'token' => hash('sha256', $raw),
            'source' => RegistrationTokenSource::MemberInvite,
            'inviter_id' => $inviter->getKey(),
            'created_at' => now(),
        ]);

        return $raw;
    }
}
