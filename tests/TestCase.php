<?php

namespace Tests;

use App\Features\Profile\ProfileVisibilityPolicy;
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

        // Seeded only for RefreshDatabase tests, which own an isolated per-process database; the others
        // share the base database across parallel processes and none depend on the seed.
        if ($this->usesRefreshDatabase() && Schema::hasTable('sns_settings')) {
            $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'open');
            $this->setSnsSetting(SnsSettingKey::CaptchaEnabled, false);
            // A member's stored Open means a web-public page only under this policy; the shipped
            // members-only default is pinned by ProfileVisibilityPolicyTest itself.
            $this->setSnsSetting(SnsSettingKey::ProfileVisibilityPolicy, ProfileVisibilityPolicy::MemberChoice);
        }
    }

    /** Whether this test isolates the database per process (and so may safely seed it). */
    private function usesRefreshDatabase(): bool
    {
        return in_array(RefreshDatabase::class, class_uses_recursive(static::class), true);
    }

    /**
     * The test container outlives a request, so between two requests in one test the objects that
     * captured the first request's session store (guards, the built session driver, the redirector)
     * and the scoped bindings must be forgotten by hand. Required when a test crosses the member/admin
     * realm boundary or changes what a scoped service already counted.
     */
    protected function freshRequestState(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app->forgetInstance('redirect');
        $this->app->forgetScopedInstances();
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
        // Scoped services hold the old answer until their scope ends, which the test container never
        // does on its own, so a test comparing counts across two surfaces must not flip a setting
        // between them.
        $this->app->forgetScopedInstances();
    }
}
