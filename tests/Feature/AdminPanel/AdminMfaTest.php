<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Auth\AdminAppAuthentication;
use App\Auth\PasswordScheme;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\SecuritySettings;
use App\Models\AdminUser;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Facades\Filament;
use Filament\Forms\Components\OneTimeCodeInput;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PragmaRX\Google2FAQRCode\Google2FA;
use PragmaRX\Google2FAQRCode\QRCode\Bacon;
use Tests\TestCase;

/**
 * Opt-in TOTP two-factor auth for the admin panel (Filament's built-in App provider).
 * The challenge is inserted by Filament's Login page after credential validation, so an
 * MFA-enabled admin must present a valid code; an admin without MFA is unaffected.
 */
class AdminMfaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    private function provider(): AdminAppAuthentication
    {
        return AdminAppAuthentication::make()->recoverable()->codeWindow(1);
    }

    /** Enable MFA on the admin the way set-up would; returns [secret, rawRecoveryCodes]. */
    private function enableMfa(AdminUser $admin): array
    {
        $provider = $this->provider();
        $secret = $provider->generateSecret();
        $codes = $provider->generateRecoveryCodes();

        $admin->saveAppAuthenticationSecret($secret);
        $provider->saveRecoveryCodes($admin, $codes); // bcrypt-hashes before storing

        return [$secret, $codes];
    }

    public function test_admin_user_satisfies_the_mfa_contracts_and_stores_the_secret_encrypted(): void
    {
        $admin = AdminUser::factory()->create();
        $this->assertInstanceOf(HasAppAuthentication::class, $admin);
        $this->assertInstanceOf(HasAppAuthenticationRecovery::class, $admin);

        [$secret, $codes] = $this->enableMfa($admin);

        $this->assertSame($secret, $admin->fresh()->getAppAuthenticationSecret());
        // The raw column is ciphertext, not the base32 secret.
        $row = DB::table('admin_users')->where('id', $admin->getKey())->first();
        $this->assertNotSame($secret, $row->app_authentication_secret);
        $this->assertStringNotContainsString($secret, (string) $row->app_authentication_secret);
        // Recovery codes are bcrypt-hashed then encrypted — no plaintext code in the column.
        foreach ($codes as $code) {
            $this->assertStringNotContainsString($code, (string) $row->app_authentication_recovery_codes);
        }
    }

    public function test_the_setup_qr_code_is_a_single_valid_data_uri_without_imagick(): void
    {
        // Force the SVG backend (the imagick-absent path, common on shared hosting). Filament's
        // parent double-base64-wraps the already-complete data URI there, yielding an image whose
        // decoded content starts with "data:" instead of "<svg" — the reported "Start tag expected".
        $this->actingAs(AdminUser::factory()->create(), 'admin');
        $google2fa = new Google2FA(
            (new Bacon)->setImageBackend(new SvgImageBackEnd),
        );
        $provider = new AdminAppAuthentication($google2fa);

        $uri = $provider->generateQrCodeDataUri($provider->generateSecret());

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $uri);
        $decoded = base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')));
        $this->assertStringStartsWith('<', ltrim($decoded), 'the QR must decode to SVG, not a nested data URI');
        $this->assertStringNotContainsString('data:image/svg+xml', $decoded, 'the data URI must not be double-encoded');
    }

    public function test_the_panel_registers_opt_in_totp_mfa(): void
    {
        $this->assertTrue(Filament::hasMultiFactorAuthentication());
        $this->assertArrayHasKey('app', Filament::getMultiFactorAuthenticationProviders());
        $this->assertFalse(Filament::getCurrentOrDefaultPanel()->isMultiFactorAuthenticationRequired());
    }

    public function test_the_security_page_is_reachable_from_the_user_menu_not_the_sidebar(): void
    {
        // Own-account 2FA lives in the avatar menu, not the sidebar (personal, not site-wide).
        $this->assertFalse(SecuritySettings::shouldRegisterNavigation());

        $this->actingAs(AdminUser::factory()->create(), 'admin');
        $items = Filament::getCurrentOrDefaultPanel()->getUserMenuItems();
        $urls = array_map(fn ($item) => $item->getUrl(), $items);
        $this->assertContains(SecuritySettings::getUrl(), $urls);
    }

    public function test_the_challenge_code_field_is_autofocused(): void
    {
        // The challenge form appears mid-page after the credentials submit; the code field must
        // carry the focus so the admin can type straight from their authenticator.
        $admin = AdminUser::factory()->create();

        $code = collect($this->provider()->getChallengeFormComponents($admin))
            ->first(fn ($component) => $component instanceof OneTimeCodeInput);

        $this->assertNotNull($code, 'expected the vendor challenge form to carry a one-time-code field');
        $this->assertTrue($code->isAutofocused());
        // The attribute alone is dead after page load; the Alpine hook does the actual focusing
        // when the challenge is morphed in (see AdminAppAuthentication::getChallengeFormComponents).
        $this->assertArrayHasKey('x-init', $code->getExtraInputAttributes());
    }

    public function test_an_admin_without_mfa_logs_in_with_password_alone(): void
    {
        AdminUser::factory()->create(['username' => 'plain', 'password' => 'secret-pass-1']);

        Livewire::test(Login::class)
            ->fillForm(['email' => 'plain', 'password' => 'secret-pass-1'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticated('admin');
    }

    public function test_an_mfa_admin_must_pass_the_totp_challenge(): void
    {
        $admin = AdminUser::factory()->create(['username' => 'mfa', 'password' => 'secret-pass-1']);
        [$secret] = $this->enableMfa($admin);

        // Correct password alone does not sign in — the challenge step intercedes.
        $component = Livewire::test(Login::class)
            ->fillForm(['email' => 'mfa', 'password' => 'secret-pass-1'])
            ->call('authenticate')
            ->assertHasNoFormErrors();
        $this->assertGuest('admin');

        // A wrong code is rejected.
        $component->set('data.multiFactor.app.code', '000000')
            ->call('authenticate')
            ->assertHasFormErrors();
        $this->assertGuest('admin');

        // The current TOTP completes the login.
        $component->set('data.multiFactor.app.code', $this->provider()->getCurrentCode($admin->fresh(), $secret))
            ->call('authenticate')
            ->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_a_recovery_code_passes_the_challenge_and_is_consumed(): void
    {
        $admin = AdminUser::factory()->create(['username' => 'mfa', 'password' => 'secret-pass-1']);
        [, $codes] = $this->enableMfa($admin);

        Livewire::test(Login::class)
            ->fillForm(['email' => 'mfa', 'password' => 'secret-pass-1'])
            ->call('authenticate')
            ->set('data.multiFactor.app.useRecoveryCode', true)
            ->set('data.multiFactor.app.recoveryCode', $codes[0])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($admin, 'admin');
        // One code is spent; the rest remain.
        $remaining = $admin->fresh()->getAppAuthenticationRecoveryCodes();
        $this->assertCount(count($codes) - 1, $remaining);
        $this->assertFalse($this->provider()->verifyRecoveryCode($codes[0], $admin->fresh()));
    }

    public function test_admin_login_offers_no_remember_me_so_mfa_cannot_be_bypassed(): void
    {
        // A recaller cookie authenticates through the guard middleware, which never runs the
        // TOTP challenge — so remember-me is removed from the admin panel entirely.
        $admin = AdminUser::factory()->create(['username' => 'noremember', 'password' => 'secret-pass-1']);

        $component = Livewire::test(Login::class)->fillForm(['email' => 'noremember', 'password' => 'secret-pass-1']);
        // The form exposes no remember toggle.
        $component->assertFormFieldDoesNotExist('remember');

        // Even if a client forges the field, no recaller cookie — the bypass token — is issued.
        $component->set('data.remember', true)->call('authenticate')->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($admin, 'admin');
        $recaller = 'remember_admin_'.sha1(SessionGuard::class);
        $this->assertArrayNotHasKey($recaller, $this->app['cookie']->getQueuedCookies());
    }

    public function test_first_login_of_a_wrapped_admin_with_mfa_retires_the_password_scheme(): void
    {
        // A8 boundary: a migrated admin's wrapped password is retired to a plain bcrypt on
        // first login, and that still happens through the MFA challenge (the rehash runs at
        // attemptWhen, after the challenge passes).
        $admin = AdminUser::factory()->create(['username' => 'wrapped']);
        DB::table('admin_users')->where('id', $admin->getKey())->update([
            'password' => Hash::make(md5('secret-pass-1')),
            'password_scheme' => PasswordScheme::Md5Bcrypt->value,
        ]);
        [$secret] = $this->enableMfa($admin);

        Livewire::test(Login::class)
            ->fillForm(['email' => 'wrapped', 'password' => 'secret-pass-1'])
            ->call('authenticate')
            ->set('data.multiFactor.app.code', $this->provider()->getCurrentCode($admin->fresh(), $secret))
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($admin, 'admin');
        $admin->refresh();
        $this->assertNull($admin->password_scheme);
        $this->assertTrue(Hash::check('secret-pass-1', $admin->password));
    }
}
