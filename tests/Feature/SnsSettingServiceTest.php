<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The global SNS settings store: an absent row resolves to the key default (which may itself fall
 * back to env/config), a stored row overrides it, and the resolved map is cached until cleared.
 */
class SnsSettingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_absent_row_resolves_to_the_key_default(): void
    {
        $service = app(SnsSettingService::class);

        $this->assertSame((string) config('app.name'), $service->get(SnsSettingKey::SnsName));
        $this->assertSame('', $service->get(SnsSettingKey::SnsTitle));
        $this->assertSame((string) config('mail.from.address'), $service->get(SnsSettingKey::AdminMailAddress));
    }

    public function test_stored_row_overrides_the_default(): void
    {
        DB::table('sns_settings')->insert(['key' => 'sns_name', 'value' => 'My Community']);

        $this->assertSame('My Community', app(SnsSettingService::class)->get(SnsSettingKey::SnsName));
    }

    public function test_resolved_map_is_cached_until_cleared(): void
    {
        $service = app(SnsSettingService::class);

        // First read caches the (empty) override map.
        $this->assertSame((string) config('app.name'), $service->get(SnsSettingKey::SnsName));

        DB::table('sns_settings')->insert(['key' => 'sns_name', 'value' => 'My Community']);

        // Still the cached default until the cache is dropped.
        $this->assertSame((string) config('app.name'), $service->get(SnsSettingKey::SnsName));

        $service->clearCache();

        $this->assertSame('My Community', $service->get(SnsSettingKey::SnsName));
    }

    public function test_captcha_enabled_decodes_fail_closed(): void
    {
        // Only an explicit '0' disables the challenge; a malformed stored value keeps it on.
        DB::table('sns_settings')->updateOrInsert(['key' => 'captcha_enabled'], ['value' => 'garbage']);
        app(SnsSettingService::class)->clearCache();

        $this->assertTrue(app(SnsSettingService::class)->get(SnsSettingKey::CaptchaEnabled));
    }

    public function test_from_op3_source_name_resolves_known_keys_only(): void
    {
        $this->assertSame(SnsSettingKey::SnsName, SnsSettingKey::fromOp3SourceName('sns_name'));
        $this->assertSame(SnsSettingKey::AdminMailAddress, SnsSettingKey::fromOp3SourceName('admin_mail_address'));
        $this->assertNull(SnsSettingKey::fromOp3SourceName('enable_pc'));
    }

    public function test_registration_mode_has_no_single_op3_source_column(): void
    {
        // It is composed from OpenPNE 3's invite_mode + enable_registration, so no 1:1 column maps to
        // it — guard against a regression that wires it to enable_registration alone.
        $this->assertNull(SnsSettingKey::RegistrationMode->op3SourceName());
        $this->assertNull(SnsSettingKey::fromOp3SourceName('enable_registration'));
        $this->assertNull(SnsSettingKey::fromOp3SourceName('invite_mode'));
    }
}
