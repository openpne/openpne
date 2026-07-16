<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Auth\AdminAppAuthentication;
use App\Auth\PasswordScheme;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\SecuritySettings;
use App\Models\AdminUser;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\CapturesSecurityLog;
use Tests\TestCase;

/**
 * Inline password re-authentication ("sudo mode") for the three admin MFA management actions.
 * All three demand the account password on top of their existing code requirements; the wrong or
 * missing password fails fast so a recovery code is never consumed, and the check is throttled by a
 * shared per-admin limiter. See App\Auth\AdminMfaPasswordReauth and docs/internals/security.md.
 */
class AdminMfaReauthTest extends TestCase
{
    use CapturesSecurityLog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    private function provider(): AdminAppAuthentication
    {
        return AdminAppAuthentication::make()->recoverable()->codeWindow(1);
    }

    /** Enable MFA the way set-up would; returns [secret, rawRecoveryCodes]. */
    private function enableMfa(AdminUser $admin): array
    {
        $provider = $this->provider();
        $secret = $provider->generateSecret();
        $codes = $provider->generateRecoveryCodes();

        $admin->saveAppAuthenticationSecret($secret);
        $provider->saveRecoveryCodes($admin, $codes);

        return [$secret, $codes];
    }

    private function currentCode(AdminUser $admin, string $secret): string
    {
        return $this->provider()->getCurrentCode($admin->fresh(), $secret);
    }

    private function page(AdminUser $admin): Testable
    {
        $this->actingAs($admin, 'admin');

        return Livewire::test(SecuritySettings::class);
    }

    /** Mount the set-up wizard and return the secret minted into its encrypted arguments. */
    private function mountSetUp(Testable $page): string
    {
        $page->mountAction(TestAction::make('setUpAppAuthentication')->schemaComponent());
        $encrypted = $page->instance()->mountedActions[0]['arguments']['encrypted'];

        return decrypt($encrypted)['secret'];
    }

    private function seedAdminSession(AdminUser $admin, string $id): void
    {
        DB::table('admin_sessions')->insert([
            'id' => $id, 'user_id' => $admin->getKey(),
            'payload' => base64_encode('{}'), 'last_activity' => time(),
        ]);
    }

    private function throttleKey(AdminUser $admin): string
    {
        return 'admin-mfa-reauth:'.$admin->getKey();
    }

    private function assertThrottleMessage(array $messages, string $key): void
    {
        $this->assertArrayHasKey($key, $messages);
        $prefix = Str::before(__('auth.throttle', ['seconds' => 999999]), '999999');
        $this->assertStringContainsString($prefix, $messages[$key][0]);
    }

    // --- set-up -------------------------------------------------------------

    public function test_set_up_without_a_password_is_rejected_and_saves_nothing(): void
    {
        config(['session.driver' => 'database']);
        $this->captureSecurityLog();
        $admin = AdminUser::factory()->create(['password' => 'secret-pass-1']);

        $page = $this->page($admin);
        $this->seedAdminSession($admin, session()->getId());
        $this->seedAdminSession($admin, 'other-device');
        $this->mountSetUp($page);

        $page->assertWizardCurrentStep(1)
            ->goToNextWizardStep()
            ->assertHasActionErrors(['current_password']);

        $this->assertNull($admin->fresh()->getAppAuthenticationSecret());
        $this->assertDatabaseHas('admin_sessions', ['id' => 'other-device']);
        $this->assertSame([], $this->securityRecords('mfa.enabled'));
    }

    public function test_set_up_with_a_wrong_password_saves_nothing(): void
    {
        $admin = AdminUser::factory()->create(['password' => 'secret-pass-1']);

        $page = $this->page($admin);
        $this->mountSetUp($page);

        $page->set('mountedActions.0.data.current_password', 'wrong-pass-9')
            ->goToNextWizardStep()
            ->assertHasActionErrors(['current_password']);

        $this->assertNull($admin->fresh()->getAppAuthenticationSecret());
    }

    public function test_set_up_full_flow_with_password_and_code_enables_and_revokes(): void
    {
        // The loud pin that the prepended identity step still renders and the wizard completes after
        // a Filament upgrade: a real mount, a real per-step nextStep, then submit.
        config(['session.driver' => 'database']);
        $this->captureSecurityLog();
        $admin = AdminUser::factory()->create(['password' => 'secret-pass-1']);

        $page = $this->page($admin);
        $this->seedAdminSession($admin, session()->getId());
        $this->seedAdminSession($admin, 'other-device');
        $secret = $this->mountSetUp($page);

        // Identity step comes first and gates on the password: the wizard opens on step 1, and only a
        // correct password advances it to the QR step — if step 1 were the code step this would error.
        $page->assertWizardCurrentStep(1)
            ->set('mountedActions.0.data.current_password', 'secret-pass-1')
            ->goToNextWizardStep()
            ->assertHasNoActionErrors()
            ->assertWizardCurrentStep(2);

        // Then the QR/code step, then submit.
        $page->set('mountedActions.0.data.code', $this->currentCode($admin, $secret))
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame($secret, $admin->fresh()->getAppAuthenticationSecret());
        $this->assertNotNull($this->securityRecords('mfa.enabled'));
        $this->assertCount(1, $this->securityRecords('mfa.enabled'));
        $this->assertDatabaseHas('admin_sessions', ['id' => session()->getId()]);
        $this->assertDatabaseMissing('admin_sessions', ['id' => 'other-device']);
    }

    // --- disable ------------------------------------------------------------

    public function test_disable_with_a_code_but_no_password_is_rejected(): void
    {
        $admin = AdminUser::factory()->create(['password' => 'secret-pass-1']);
        [$secret] = $this->enableMfa($admin);

        $this->page($admin)->callAction(
            TestAction::make('disableAppAuthentication')->schemaComponent(),
            ['code' => $this->currentCode($admin, $secret)],
        )->assertHasActionErrors(['current_password']);

        $this->assertNotNull($admin->fresh()->getAppAuthenticationSecret());
    }

    public function test_disable_with_a_password_but_no_code_reports_the_code_requirement(): void
    {
        // The vendor possession requirement is kept: password alone does not disable.
        $admin = AdminUser::factory()->create(['password' => 'secret-pass-1']);
        $this->enableMfa($admin);

        $this->page($admin)->callAction(
            TestAction::make('disableAppAuthentication')->schemaComponent(),
            ['current_password' => 'secret-pass-1'],
        )->assertHasActionErrors(['code']);

        $this->assertNotNull($admin->fresh()->getAppAuthenticationSecret());
    }

    public function test_disable_with_password_and_code_disables_and_revokes(): void
    {
        config(['session.driver' => 'database']);
        $this->captureSecurityLog();
        $admin = AdminUser::factory()->create(['password' => 'secret-pass-1']);
        [$secret] = $this->enableMfa($admin);

        $page = $this->page($admin);
        $this->seedAdminSession($admin, session()->getId());
        $this->seedAdminSession($admin, 'other-device');

        $page->callAction(
            TestAction::make('disableAppAuthentication')->schemaComponent(),
            ['current_password' => 'secret-pass-1', 'code' => $this->currentCode($admin, $secret)],
        )->assertHasNoActionErrors();

        $this->assertNull($admin->fresh()->getAppAuthenticationSecret());
        $this->assertCount(1, $this->securityRecords('mfa.disabled'));
        $this->assertDatabaseHas('admin_sessions', ['id' => session()->getId()]);
        $this->assertDatabaseMissing('admin_sessions', ['id' => 'other-device']);
    }

    public function test_disable_with_password_and_recovery_code_disables(): void
    {
        $admin = AdminUser::factory()->create(['password' => 'secret-pass-1']);
        [, $codes] = $this->enableMfa($admin);

        $this->page($admin)->callAction(
            TestAction::make('disableAppAuthentication')->schemaComponent(),
            ['current_password' => 'secret-pass-1', 'useRecoveryCode' => true, 'recoveryCode' => $codes[0]],
        )->assertHasNoActionErrors();

        $this->assertNull($admin->fresh()->getAppAuthenticationSecret());
    }

    public function test_a_wrong_or_missing_password_never_consumes_a_recovery_code(): void
    {
        // The fail-fast invariant: the password rule throws before the vendor recovery-code rule runs,
        // so a valid recovery code is neither spent nor logged when the password is wrong or absent.
        $this->captureSecurityLog();
        $admin = AdminUser::factory()->create(['password' => 'secret-pass-1']);
        [, $codes] = $this->enableMfa($admin);
        $storedCodes = $admin->fresh()->getRawOriginal('app_authentication_recovery_codes');

        foreach (['wrong-pass-9', ''] as $password) {
            $this->page($admin)->callAction(
                TestAction::make('disableAppAuthentication')->schemaComponent(),
                ['current_password' => $password, 'useRecoveryCode' => true, 'recoveryCode' => $codes[0]],
            )->assertHasActionErrors(['current_password']);

            $this->assertNotNull($admin->fresh()->getAppAuthenticationSecret());
            $this->assertSame($storedCodes, $admin->fresh()->getRawOriginal('app_authentication_recovery_codes'));
        }

        $this->assertSame([], $this->securityRecords('mfa.recovery_code_used'));
        // The unspent code still verifies.
        $this->assertTrue($this->provider()->verifyRecoveryCode($codes[0], $admin->fresh()));
    }

    // --- regenerate ---------------------------------------------------------

    public function test_regenerate_with_only_a_password_now_requires_a_code(): void
    {
        // Posture change: this used to succeed with the password alone.
        $admin = AdminUser::factory()->create(['password' => 'secret-pass-1']);
        $this->enableMfa($admin);
        $before = $admin->fresh()->getRawOriginal('app_authentication_recovery_codes');

        $this->page($admin)->callAction(
            TestAction::make('regenerateAppAuthenticationRecoveryCodes')->schemaComponent(),
            ['password' => 'secret-pass-1'],
        )->assertHasActionErrors(['code']);

        $this->assertSame($before, $admin->fresh()->getRawOriginal('app_authentication_recovery_codes'));
    }

    public function test_regenerate_with_only_a_code_requires_the_password(): void
    {
        $admin = AdminUser::factory()->create(['password' => 'secret-pass-1']);
        [$secret] = $this->enableMfa($admin);
        $before = $admin->fresh()->getRawOriginal('app_authentication_recovery_codes');

        $this->page($admin)->callAction(
            TestAction::make('regenerateAppAuthenticationRecoveryCodes')->schemaComponent(),
            ['code' => $this->currentCode($admin, $secret)],
        )->assertHasActionErrors(['password']);

        $this->assertSame($before, $admin->fresh()->getRawOriginal('app_authentication_recovery_codes'));
    }

    public function test_regenerate_with_password_and_code_rotates_without_revoking(): void
    {
        config(['session.driver' => 'database']);
        $this->captureSecurityLog();
        $admin = AdminUser::factory()->create(['password' => 'secret-pass-1']);
        [$secret] = $this->enableMfa($admin);
        $before = $admin->fresh()->getRawOriginal('app_authentication_recovery_codes');

        $page = $this->page($admin);
        $this->seedAdminSession($admin, session()->getId());
        $this->seedAdminSession($admin, 'other-device');

        $page->callAction(
            TestAction::make('regenerateAppAuthenticationRecoveryCodes')->schemaComponent(),
            ['current_password' => 'secret-pass-1', 'password' => 'secret-pass-1', 'code' => $this->currentCode($admin, $secret)],
        )->assertHasNoActionErrors();

        $this->assertNotSame($before, $admin->fresh()->getRawOriginal('app_authentication_recovery_codes'));
        $this->assertCount(1, $this->securityRecords('mfa.recovery_codes_regenerated'));
        // Rotating backup codes leaves the TOTP factor unchanged, so other sessions are kept.
        $this->assertDatabaseHas('admin_sessions', ['id' => 'other-device']);
    }

    // --- legacy provider boundary ------------------------------------------

    public function test_a_retired_legacy_password_is_accepted_after_login(): void
    {
        $admin = AdminUser::factory()->create(['username' => 'wrapped']);
        DB::table('admin_users')->where('id', $admin->getKey())->update([
            'password' => Hash::make(md5('secret-pass-1')),
            'password_scheme' => PasswordScheme::Md5Bcrypt->value,
        ]);
        [$secret] = $this->enableMfa($admin);

        // The real login retires the md5_bcrypt wrap to a plain bcrypt.
        Livewire::test(Login::class)
            ->fillForm(['email' => 'wrapped', 'password' => 'secret-pass-1'])
            ->call('authenticate')
            ->set('data.multiFactor.app.code', $this->currentCode($admin, $secret))
            ->call('authenticate')
            ->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($admin, 'admin');

        // The disable modal's Hash::check then accepts the plaintext.
        Livewire::test(SecuritySettings::class)->callAction(
            TestAction::make('disableAppAuthentication')->schemaComponent(),
            ['current_password' => 'secret-pass-1', 'code' => $this->currentCode($admin, $secret)],
        )->assertHasNoActionErrors();

        $this->assertNull($admin->fresh()->getAppAuthenticationSecret());
    }

    // --- throttle -----------------------------------------------------------

    public function test_the_wizard_password_step_is_throttled_where_the_action_limit_does_not_apply(): void
    {
        // Wizard::nextStep validates the step's schema directly, bypassing the action-level
        // ->rateLimit(5) that only runs in callMountedAction — so the rule-internal per-admin limiter
        // is the sole guard against unlimited password guesses here. Five wrong guesses on one mount
        // then a sixth on a fresh mount (budget survives the remount) is throttled even when correct.
        $admin = AdminUser::factory()->create(['password' => 'secret-pass-1']);

        $page = $this->page($admin);
        $this->mountSetUp($page);
        for ($i = 0; $i < 5; $i++) {
            $page->set('mountedActions.0.data.current_password', 'wrong-pass-9')
                ->goToNextWizardStep()
                ->assertHasActionErrors(['current_password']);
        }

        $fresh = $this->page($admin);
        $this->mountSetUp($fresh);
        $fresh->set('mountedActions.0.data.current_password', 'secret-pass-1')
            ->goToNextWizardStep();

        $this->assertThrottleMessage($fresh->errors()->messages(), 'mountedActions.0.data.current_password');
        $this->assertNull($admin->fresh()->getAppAuthenticationSecret());
    }

    public function test_the_password_budget_is_shared_across_the_disable_and_regenerate_modals(): void
    {
        // The limiter is keyed on the admin, not the modal, so exhausting it on disable also blocks
        // regenerate — an attacker cannot multiply the budget by hopping between the three modals.
        $admin = AdminUser::factory()->create(['password' => 'secret-pass-1']);
        [$secret] = $this->enableMfa($admin);
        $before = $admin->fresh()->getRawOriginal('app_authentication_recovery_codes');

        for ($i = 0; $i < 5; $i++) {
            $this->page($admin)->callAction(
                TestAction::make('disableAppAuthentication')->schemaComponent(),
                ['current_password' => 'wrong-pass-9'],
            )->assertHasActionErrors(['current_password']);
        }

        // The regenerate modal's own action limiter is untouched, yet the shared per-admin budget is
        // spent, so a fully correct regenerate is still rejected and nothing rotates.
        $regen = $this->page($admin)->callAction(
            TestAction::make('regenerateAppAuthenticationRecoveryCodes')->schemaComponent(),
            ['password' => 'secret-pass-1', 'code' => $this->currentCode($admin, $secret)],
        );
        $this->assertThrottleMessage($regen->errors()->messages(), 'mountedActions.0.data.password');
        $this->assertSame($before, $admin->fresh()->getRawOriginal('app_authentication_recovery_codes'));
        $this->assertNotNull($admin->fresh()->getAppAuthenticationSecret());
    }

    public function test_a_successful_reauth_clears_the_throttle(): void
    {
        $admin = AdminUser::factory()->create(['password' => 'secret-pass-1']);
        [$secret] = $this->enableMfa($admin);
        $disable = TestAction::make('disableAppAuthentication')->schemaComponent();

        $this->page($admin)->callAction($disable, ['current_password' => 'wrong-pass-9'])
            ->assertHasActionErrors(['current_password']);
        $this->assertSame(1, RateLimiter::attempts($this->throttleKey($admin)));

        $this->page($admin)->callAction(
            $disable,
            ['current_password' => 'secret-pass-1', 'code' => $this->currentCode($admin, $secret)],
        )->assertHasNoActionErrors();

        $this->assertNull($admin->fresh()->getAppAuthenticationSecret());
        $this->assertSame(0, RateLimiter::attempts($this->throttleKey($admin)));
    }
}
