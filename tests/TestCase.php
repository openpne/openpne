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
        // Atomic upsert, not updateOrInsert: Unit tests don't use RefreshDatabase, so under
        // parallel MySQL they share one database and their setUp() baseline seeds race —
        // updateOrInsert's SELECT-then-INSERT loses to a duplicate-key 1062.
        DB::table('sns_settings')->upsert(
            [['key' => $key->value, 'value' => $key->encode($key->coerce($value))]],
            ['key'],
            ['value'],
        );
        app(SnsSettingService::class)->clearCache();
    }
}
