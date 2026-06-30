<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Member;
use App\Notifications\Auth\RegistrationLinkNotification;
use App\Notifications\Auth\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proves the SNS settings are consumed, not write-only: the global helpers reflect stored overrides
 * and system mail is sent from the configured administrator address / SNS name.
 */
class SnsSettingWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_helpers_return_stored_overrides(): void
    {
        DB::table('sns_settings')->insert([
            ['key' => 'sns_name', 'value' => 'My Community'],
            ['key' => 'sns_title', 'value' => 'Welcome'],
            ['key' => 'admin_mail_address', 'value' => 'ops@example.test'],
        ]);

        $this->assertSame('My Community', sns_name());
        $this->assertSame('Welcome', sns_title());
        $this->assertSame('ops@example.test', sns_admin_mail_address());
    }

    public function test_system_mail_uses_the_configured_sns_from_address(): void
    {
        DB::table('sns_settings')->insert([
            ['key' => 'sns_name', 'value' => 'My Community'],
            ['key' => 'admin_mail_address', 'value' => 'ops@example.test'],
        ]);

        $mail = (new RegistrationLinkNotification('raw-token', 'en'))->toMail(new AnonymousNotifiable);

        $this->assertSame(['ops@example.test', 'My Community'], $mail->from);
        $this->assertStringContainsString('My Community', $mail->subject);
    }

    public function test_classic_document_title_reflects_sns_title(): void
    {
        // The Classic <title> suffix follows OpenPNE 3's frontend rule: sns_title, or sns_name when
        // unset. (/login renders the Classic surface under the default tenant config.)
        DB::table('sns_settings')->insert(['key' => 'sns_title', 'value' => 'My Community Portal']);

        $this->get('/login')
            ->assertOk()
            ->assertSee('| My Community Portal</title>', false);
    }

    public function test_notification_mail_is_branded_with_sns_name_and_drops_the_laravel_logo(): void
    {
        DB::table('sns_settings')->insert([
            ['key' => 'sns_name', 'value' => 'My Community'],
            ['key' => 'admin_mail_address', 'value' => 'ops@example.test'],
        ]);

        $html = $this->renderNotificationMail(
            (new RegistrationLinkNotification('raw-token', 'en'))->toMail(new AnonymousNotifiable)
        );

        // The framework header injects laravel.com's logo image when the brand is literally "Laravel".
        $this->assertStringNotContainsString('notification-logo-v2.1.png', $html);
        $this->assertStringNotContainsString('Laravel Logo', $html);
        // Header, footer (copyright) and salutation each render the site name — proving the
        // markdown.paths wiring and both layout overrides, not just the salutation.
        $this->assertGreaterThanOrEqual(3, substr_count($html, 'My Community'));
        $this->assertStringContainsString('My Community. ', $html); // footer copyright line
    }

    public function test_password_reset_mail_salutation_uses_sns_name(): void
    {
        DB::table('sns_settings')->insert([
            ['key' => 'sns_name', 'value' => 'My Community'],
            ['key' => 'admin_mail_address', 'value' => 'ops@example.test'],
        ]);
        $member = Member::factory()->create();

        $html = $this->renderNotificationMail(
            (new ResetPasswordNotification('raw-token', 'en'))->toMail($member)
        );

        $this->assertStringContainsString('— My Community', $html);
        $this->assertStringNotContainsString('notification-logo-v2.1.png', $html);
    }

    /** Render a notification MailMessage to HTML via the same path MailChannel uses. */
    private function renderNotificationMail(MailMessage $message): string
    {
        $theme = $message->theme ?? config('mail.markdown.theme', 'default');

        return (string) app(Markdown::class)->theme($theme)->render($message->markdown, $message->data());
    }
}
