<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Notifications\Auth\RegistrationLinkNotification;
use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;
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

    public function test_every_key_answers_every_registry_accessor(): void
    {
        // The registry's matches have no default arm; a case one of them forgets is a 500 nobody
        // meets until a page iterates that group.
        foreach (SnsSettingKey::cases() as $key) {
            $key->group();
            $key->op3SourceName();
            $key->isMigratedFromOp3();
            $key->label();
            $key->isRequired();
            $key->maxBytes();
            $default = $key->default();
            // decode() answers null before its match, so the round trip is what reaches the arms.
            $this->assertSame($default, $key->decode($key->encode($key->coerce($default))), $key->value);
        }
    }

    public function test_helpers_return_stored_overrides(): void
    {
        DB::table('sns_settings')->insert([
            ['key' => 'sns_name', 'value' => 'My Group'],
            ['key' => 'sns_title', 'value' => 'Welcome'],
            ['key' => 'admin_mail_address', 'value' => 'ops@example.test'],
        ]);

        $this->assertSame('My Group', sns_name());
        $this->assertSame('Welcome', sns_title());
        $this->assertSame('ops@example.test', sns_admin_mail_address());
    }

    public function test_branding_helpers_return_stored_overrides(): void
    {
        DB::table('sns_settings')->insert([
            ['key' => 'brand_color', 'value' => '#0088aa'],
            ['key' => 'brand_logo_file', 'value' => 'logo-token'],
            ['key' => 'brand_favicon_file', 'value' => 'favicon-token'],
        ]);

        $this->assertSame('#0088aa', brand_color());
        $this->assertSame(route('file.public', ['file' => 'logo-token']), brand_logo_url());
        $this->assertSame(route('file.public', ['file' => 'favicon-token']), brand_favicon_url());
    }

    public function test_branding_helpers_read_unset_and_corrupt_values_as_unbranded(): void
    {
        // Absent (fresh install).
        $this->assertNull(brand_color());
        $this->assertNull(brand_logo_url());
        $this->assertNull(brand_favicon_url());

        // Stored, but empty or not a hex color: the value is inlined into a style attribute and a
        // JSON prop, so anything unusable has to read as unset.
        DB::table('sns_settings')->insert([
            ['key' => 'brand_color', 'value' => 'rebeccapurple'],
            ['key' => 'brand_logo_file', 'value' => ''],
            ['key' => 'brand_favicon_file', 'value' => ''],
        ]);
        app(SnsSettingService::class)->clearCache();

        $this->assertNull(brand_color());
        $this->assertNull(brand_logo_url());
        $this->assertNull(brand_favicon_url());
    }

    public function test_system_mail_uses_the_configured_sns_from_address(): void
    {
        DB::table('sns_settings')->insert([
            ['key' => 'sns_name', 'value' => 'My Group'],
            ['key' => 'admin_mail_address', 'value' => 'ops@example.test'],
        ]);

        $mail = (new RegistrationLinkNotification('raw-token', 'en'))->toMail(new AnonymousNotifiable);

        $this->assertSame(['ops@example.test', 'My Group'], $mail->from);
        $this->assertStringContainsString('My Group', $mail->subject);
    }

    public function test_classic_document_title_reflects_sns_title(): void
    {
        // The Classic <title> suffix follows OpenPNE 3's frontend rule: sns_title, or sns_name when
        // unset. (/login renders the Classic surface under the default config.)
        DB::table('sns_settings')->insert(['key' => 'sns_title', 'value' => 'My Group Portal']);

        $this->get('/login')
            ->assertOk()
            ->assertSee('| My Group Portal</title>', false);
    }
}
