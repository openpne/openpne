<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Mail\Template\MailTemplate;
use App\Mail\Template\MailTemplateService;
use App\Models\DirectMessage;
use App\Models\DirectMessageFile;
use App\Models\Member;
use App\Notifications\Auth\RegistrationLinkNotification;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\DirectMessage\DirectMessageReceivedNotification;
use App\Notifications\Friend\FriendRequestedNotification;
use App\Notifications\Member\EmailChangeConfirmationNotification;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MailTemplateNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setSnsSetting(SnsSettingKey::SnsName, 'My Group');
        $this->setSnsSetting(SnsSettingKey::AdminMailAddress, 'ops@example.test');
    }

    public function test_mail_is_from_the_sns_address_without_laravel_branding(): void
    {
        $mail = (new RegistrationLinkNotification('raw-token', 'en'))->toMail(new AnonymousNotifiable);

        $this->assertSame(['ops@example.test', 'My Group'], $mail->from);
        $this->assertSame('My Group Letter of invitation', $mail->subject);

        $text = $this->renderMailText($mail);
        $this->assertStringNotContainsString('notification-logo-v2.1.png', $text);
        $this->assertStringNotContainsString('Laravel', $text);
    }

    public function test_mail_is_delivered_as_a_single_text_plain_part(): void
    {
        $mail = (new RegistrationLinkNotification('raw-token', 'en'))->toMail(new AnonymousNotifiable);

        // An 'html' view would make Laravel emit a multipart/HTML mail, which clients then show as HTML
        // and stop auto-linking bare URLs.
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

    public function test_a_picture_only_message_mails_the_stand_in_for_its_body(): void
    {
        $this->quoteTheBodyInTheMessageMail();
        [$sender, $recipient] = Member::factory()->count(2)->create()->all();
        $pictures = DirectMessage::factory()->create(['sender_id' => $sender->getKey(), 'subject' => null, 'body' => '']);
        DirectMessageFile::factory()->create(['direct_message_id' => $pictures->getKey()]);
        $subjectOnly = DirectMessage::factory()->create(['sender_id' => $sender->getKey(), 'subject' => 'Only a subject', 'body' => '']);

        app()->setLocale('en');
        $this->assertStringContainsString(
            'Body: '.__('Image'),
            $this->renderMailText((new DirectMessageReceivedNotification($sender, $pictures))->toMail($recipient)),
        );
        $this->assertStringContainsString(
            "Body: \n",
            $this->renderMailText((new DirectMessageReceivedNotification($sender, $subjectOnly))->toMail($recipient)),
        );
    }

    /** The stock OpenPNE 3 wording links to the message rather than quoting it; this is the wording that quotes it. */
    private function quoteTheBodyInTheMessageMail(): void
    {
        $id = DB::table('mail_templates')->insertGetId([
            'key' => MailTemplate::DirectMessageReceived->value,
            'is_enabled' => true,
        ]);
        DB::table('mail_template_translations')->insert([
            'mail_template_id' => $id,
            'locale' => 'en',
            'subject' => 'A message arrived',
            'body' => "Body: {{ message_body }}\n",
        ]);
    }

    public function test_member_name_renders_verbatim_without_app_generated_html(): void
    {
        $requester = Member::factory()->create(['name' => '[x](http://evil.test) <script>alert(1)</script>']);
        $recipient = Member::factory()->create();

        $text = $this->renderMailText((new FriendRequestedNotification($requester))->toMail($recipient));

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

        $this->assertStringContainsString('/member/config/email/confirm/the-token', $text);
        $this->assertStringNotContainsString('configComplete', $text);
    }

    public function test_disabling_a_configurable_template_drops_the_mail_channel_only(): void
    {
        $requester = Member::factory()->create();
        $recipient = Member::factory()->create();

        $this->assertSame(['mail', 'database'], (new FriendRequestedNotification($requester))->via($recipient));

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

        // The signature must appear exactly once: the service appends it, the view does not.
        $this->assertSame(1, substr_count($text, 'ops@example.test'));
    }
}
