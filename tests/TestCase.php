<?php

namespace Tests;

use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        // Registration mode and the CAPTCHA toggle are DB-backed (App\Support\SnsSettingKey), no longer
        // env. Seed the convenient test baseline the suite assumes — open registration, CAPTCHA off —
        // so most auth tests need no setup; the few that exercise a mode override it with setSnsSetting().
        if (Schema::hasTable('sns_settings')) {
            $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'open');
            $this->setSnsSetting(SnsSettingKey::CaptchaEnabled, false);
        }
    }

    /** Persist a global SNS setting for the test and refresh the cached map. */
    protected function setSnsSetting(SnsSettingKey $key, mixed $value): void
    {
        DB::table('sns_settings')->updateOrInsert(
            ['key' => $key->value],
            ['value' => $key->encode($key->coerce($value))],
        );
        app(SnsSettingService::class)->clearCache();
    }
}
