<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Notifications\Auth\RegistrationLinkNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
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
}
