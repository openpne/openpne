<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Mail\Template\MailTemplate;
use App\Mail\Template\MailTemplateService;
use App\Models\Member;
use App\Notifications\Auth\RegistrationLinkNotification;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Friend\FriendRequestedNotification;
use App\Notifications\Member\EmailChangeConfirmationNotification;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The template-bodied notifications render through the mail-template engine and deliver as plain text (text/plain). */
class MailTemplateNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setSnsSetting(SnsSettingKey::SnsName, 'My Community');
        $this->setSnsSetting(SnsSettingKey::AdminMailAddress, 'ops@example.test');
    }

    public function test_mail_is_from_the_sns_address_without_laravel_branding(): void
    {
        $mail = (new RegistrationLinkNotification('raw-token', 'en'))->toMail(new AnonymousNotifiable);

        $this->assertSame(['ops@example.test', 'My Community'], $mail->from);
        $this->assertSame('My Community Letter of invitation', $mail->subject);

        // Plain text: no HTML shell, no default Laravel notification branding leaking into the body.
        $text = $this->renderMailText($mail);
        $this->assertStringNotContainsString('notification-logo-v2.1.png', $text);
        $this->assertStringNotContainsString('Laravel', $text);
    }

    public function test_mail_is_delivered_as_a_single_text_plain_part(): void
    {
        $mail = (new RegistrationLinkNotification('raw-token', 'en'))->toMail(new AnonymousNotifiable);

        // OpenPNE 3 parity: text/plain only. An 'html' view would make Laravel emit a multipart/HTML
        // mail, which clients then show as HTML and stop auto-linking bare URLs.
        $this->assertSame(['text' => 'mail.template-text'], $mail->view);
    }

    public function test_friend_request_localizes_to_the_active_locale(): void
    {
        $requester = Member::factory()->create(['name' => 'Bob']);
        $recipient = Member::factory()->create();

        app()->setLocale('ja');
        $ja = $this->renderMailText((new FriendRequestedNotification($requester))->toMail($recipient));
        $this->assertStringContainsString('リクエストが届きました', $ja);
        $this->assertStringContainsString('Bob', $ja);

        app()->setLocale('en');
        $en = $this->renderMailText((new FriendRequestedNotification($requester))->toMail($recipient));
        $this->assertStringContainsString('Bob sent you a friend request', $en);
    }

    public function test_member_name_renders_verbatim_without_app_generated_html(): void
    {
        $requester = Member::factory()->create(['name' => '[x](http://evil.test) <script>alert(1)</script>']);
        $recipient = Member::factory()->create();

        $text = $this->renderMailText((new FriendRequestedNotification($requester))->toMail($recipient));

        // text/plain: the name is emitted verbatim (no markdown re-render) and the app never wraps it in
        // an <a> link — a receiving client may still auto-link a bare URL, but that is its choice, not ours.
        $this->assertStringContainsString('[x](http://evil.test) <script>alert(1)</script>', $text);
        $this->assertStringNotContainsString('<a href="http://evil.test', $text);
    }

    public function test_password_reset_mail_carries_the_reset_url(): void
    {
        $member = Member::factory()->create();

        $mail = (new ResetPasswordNotification('the-token', 'en'))->toMail($member);

        $this->assertSame('Reset your password', $mail->subject);
        $this->assertStringContainsString('/reset-password/the-token', $this->renderMailText($mail));
    }

    public function test_email_change_confirm_maps_url_to_token_only_and_keeps_id_available(): void
    {
        $member = Member::factory()->create();

        $text = $this->renderMailText(
            (new EmailChangeConfirmationNotification('the-token', (int) $member->getKey(), 'en'))->toMail(new AnonymousNotifiable),
        );

        // OpenPNE 4 URL is token-only (id/type dropped from the link)...
        $this->assertStringContainsString('/member/config/email/confirm/the-token', $text);
        $this->assertStringNotContainsString('configComplete', $text);
    }

    public function test_disabling_a_configurable_template_drops_the_mail_channel_only(): void
    {
        $requester = Member::factory()->create();
        $recipient = Member::factory()->create();

        // Enabled by default (no row): mail + the in-app record.
        $this->assertSame(['mail', 'database'], (new FriendRequestedNotification($requester))->via($recipient));

        // Admin turns it off: the mail drops, the in-app record stays.
        DB::table('mail_templates')->insert(['key' => MailTemplate::FriendRequested->value, 'is_enabled' => false]);
        app(MailTemplateService::class)->clearCache();
        $this->assertSame(['database'], (new FriendRequestedNotification($requester))->via($recipient));
    }

    public function test_a_required_mail_is_not_gated_by_a_disabled_row(): void
    {
        DB::table('mail_templates')->insert(['key' => MailTemplate::RegistrationLink->value, 'is_enabled' => false]);
        app(MailTemplateService::class)->clearCache();

        $this->assertSame(['mail'], (new RegistrationLinkNotification('t', 'en'))->via(new AnonymousNotifiable));
    }

    public function test_signature_is_appended_once_to_the_body(): void
    {
        $member = Member::factory()->create();
        $text = $this->renderMailText((new ResetPasswordNotification('t', 'en'))->toMail($member));

        // The default signature carries the contact line; it must appear exactly once (service appends it,
        // the view does not).
        $this->assertSame(1, substr_count($text, 'ops@example.test'));
    }
}
