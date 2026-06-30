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

/** The 7 notifications render through the mail-template engine + branded non-markdown view (PR2 wiring). */
class MailTemplateNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setSnsSetting(SnsSettingKey::SnsName, 'My Community');
        $this->setSnsSetting(SnsSettingKey::AdminMailAddress, 'ops@example.test');
    }

    public function test_mail_is_from_the_sns_address_and_branded_without_a_laravel_logo(): void
    {
        $mail = (new RegistrationLinkNotification('raw-token', 'en'))->toMail(new AnonymousNotifiable);

        $this->assertSame(['ops@example.test', 'My Community'], $mail->from);
        $this->assertSame('My Community Letter of invitation', $mail->subject);

        $html = $this->renderMailHtml($mail);
        $this->assertStringContainsString('My Community', $html);             // header + footer brand
        $this->assertStringNotContainsString('notification-logo-v2.1.png', $html);
        $this->assertStringNotContainsString('Laravel', $html);
    }

    public function test_friend_request_localizes_to_the_active_locale(): void
    {
        $requester = Member::factory()->create(['name' => 'Bob']);
        $recipient = Member::factory()->create();

        app()->setLocale('ja');
        $ja = $this->renderMailHtml((new FriendRequestedNotification($requester))->toMail($recipient));
        $this->assertStringContainsString('リクエストが届きました', $ja);
        $this->assertStringContainsString('Bob', $ja);

        app()->setLocale('en');
        $en = $this->renderMailHtml((new FriendRequestedNotification($requester))->toMail($recipient));
        $this->assertStringContainsString('Bob sent you a friend request', $en);
    }

    public function test_member_name_renders_as_text_never_a_live_link_or_script(): void
    {
        $requester = Member::factory()->create(['name' => '[x](http://evil.test) <script>alert(1)</script>']);
        $recipient = Member::factory()->create();

        $html = $this->renderMailHtml((new FriendRequestedNotification($requester))->toMail($recipient));

        $this->assertStringNotContainsString('<a href="http://evil.test', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_password_reset_mail_carries_the_reset_url(): void
    {
        $member = Member::factory()->create();

        $mail = (new ResetPasswordNotification('the-token', 'en'))->toMail($member);

        $this->assertSame('Reset your password', $mail->subject);
        $this->assertStringContainsString('/reset-password/the-token', $this->renderMailHtml($mail));
    }

    public function test_email_change_confirm_maps_url_to_token_only_and_keeps_id_available(): void
    {
        $member = Member::factory()->create();

        $html = $this->renderMailHtml(
            (new EmailChangeConfirmationNotification('the-token', (int) $member->getKey(), 'en'))->toMail(new AnonymousNotifiable),
        );

        // OpenPNE 4 URL is token-only (id/type dropped from the link)...
        $this->assertStringContainsString('/member/config/email/confirm/the-token', $html);
        $this->assertStringNotContainsString('configComplete', $html);
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
