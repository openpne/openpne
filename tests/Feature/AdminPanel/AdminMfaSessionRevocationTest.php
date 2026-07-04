<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Auth\AdminAppAuthentication;
use App\Filament\Pages\SecuritySettings;
use App\Filament\Widgets\MfaReminderWidget;
use App\Models\AdminUser;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Changing the MFA factor set is a credential change, so it revokes the admin's other
 * sessions — consistent with a password change (App\Auth\SessionRevocation). The set-up
 * and disable actions keep the current session; regenerating recovery codes does not
 * revoke. The CLI disable revokes all.
 */
class AdminMfaSessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        config(['session.driver' => 'database']);
    }

    private function seedAdminSession(AdminUser $admin, string $id): void
    {
        DB::table('admin_sessions')->insert([
            'id' => $id, 'user_id' => $admin->getKey(),
            'payload' => base64_encode('{}'), 'last_activity' => time(),
        ]);
    }

    private function action(string $name): Action
    {
        foreach (AdminAppAuthentication::make()->recoverable()->getActions() as $action) {
            if ($action->getName() === $name) {
                return $action;
            }
        }

        $this->fail("Action [{$name}] not found on the provider.");
    }

    public function test_enabling_mfa_revokes_other_admin_sessions(): void
    {
        $this->assertFactorChangeRevokes('setUpAppAuthentication');
    }

    public function test_disabling_mfa_revokes_other_admin_sessions(): void
    {
        $this->assertFactorChangeRevokes('disableAppAuthentication');
    }

    private function assertFactorChangeRevokes(string $actionName): void
    {
        $admin = AdminUser::factory()->create();
        $this->actingAs($admin, 'admin');
        $this->seedAdminSession($admin, session()->getId());
        $this->seedAdminSession($admin, 'other-device');

        // Invoke only the after-hook our decorator added (the action's own modal/effect is
        // Filament's; the revocation is ours).
        $this->action($actionName)->callAfter();

        $this->assertDatabaseHas('admin_sessions', ['id' => session()->getId()]);
        $this->assertDatabaseMissing('admin_sessions', ['id' => 'other-device']);
    }

    public function test_regenerating_recovery_codes_does_not_revoke(): void
    {
        $admin = AdminUser::factory()->create();
        $this->actingAs($admin, 'admin');
        $this->seedAdminSession($admin, 'other-device');

        $this->action('regenerateAppAuthenticationRecoveryCodes')->callAfter();

        $this->assertDatabaseHas('admin_sessions', ['id' => 'other-device']);
    }

    public function test_the_security_page_renders(): void
    {
        $this->actingAs(AdminUser::factory()->create(), 'admin');

        $this->get(SecuritySettings::getUrl())->assertOk();
    }

    public function test_the_setup_description_is_product_neutral_without_a_region_locked_link(): void
    {
        // Our override of Filament's default: no US-fixed App Store link, and not a single
        // product. The vendor default deep-links itunes.apple.com/us/... for Google Authenticator.
        foreach (['ja', 'en'] as $locale) {
            $description = trans('filament-panels::auth/multi-factor/app/actions/set-up.modal.description', [], $locale);
            $this->assertStringNotContainsString('itunes.apple.com/us', $description, $locale);
            $this->assertStringNotContainsString('/us/app/', $description, $locale);
        }

        // The unrelated keys still come from the package (the override is a per-key merge).
        $this->assertNotSame(
            'filament-panels::auth/multi-factor/app/actions/set-up.modal.heading',
            trans('filament-panels::auth/multi-factor/app/actions/set-up.modal.heading', [], 'ja'),
        );
    }

    public function test_the_reminder_shows_only_while_mfa_is_off(): void
    {
        $admin = AdminUser::factory()->create();
        $this->actingAs($admin, 'admin');

        // Off: the call-to-action is visible and links to the Security page.
        $this->assertTrue(MfaReminderWidget::canView());
        Livewire::test(MfaReminderWidget::class)
            ->assertSee(__('Set up two-factor authentication'))
            ->assertSee(SecuritySettings::getUrl());

        // On: a nudge you've acted on should not linger.
        $admin->saveAppAuthenticationSecret(AdminAppAuthentication::make()->generateSecret());
        $this->assertFalse(MfaReminderWidget::canView());
    }
}
