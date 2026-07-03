<?php

namespace Tests;

use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Notifications\Messages\MailMessage;
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
        //
        // Only for RefreshDatabase tests, which get an isolated per-process database. Other tests share
        // the base database across parallel processes, so writing here pollutes it (and previously raced
        // a duplicate-key 1062); none of them depend on the seed.
        if ($this->usesRefreshDatabase() && Schema::hasTable('sns_settings')) {
            $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'open');
            $this->setSnsSetting(SnsSettingKey::CaptchaEnabled, false);
        }
    }

    /** Whether this test isolates the database per process (and so may safely seed it). */
    private function usesRefreshDatabase(): bool
    {
        return in_array(RefreshDatabase::class, class_uses_recursive(static::class), true);
    }

    /**
     * Simulate the next HTTP request arriving on a fresh worker: forget every cached
     * object that captured the previous request's session store (guards, the session
     * manager's built driver, the redirector), so UseAdminSessionStore's per-surface
     * pin can take effect. Required between requests in any test that crosses the
     * member/admin surface boundary within one test method.
     */
    protected function freshRequestState(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app->forgetInstance('redirect');
    }

    /** Render a templated notification mail's plain-text body (the MailMessage delivers text/plain only). */
    protected function renderMailText(MailMessage $mail): string
    {
        return view($mail->view['text'], $mail->viewData)->render();
    }

    /** Persist a global SNS setting for the test and refresh the cached map. */
    protected function setSnsSetting(SnsSettingKey $key, mixed $value): void
    {
        DB::table('sns_settings')->upsert(
            [['key' => $key->value, 'value' => $key->encode($key->coerce($value))]],
            ['key'],
            ['value'],
        );
        app(SnsSettingService::class)->clearCache();
    }
}
