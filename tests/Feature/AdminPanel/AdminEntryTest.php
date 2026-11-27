<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Auth\AdminEntry;
use App\Auth\Reauth\PasskeyProof;
use App\Auth\Reauth\ReauthPurpose;
use App\Filament\Widgets\RegistrationModeWidget;
use App\Models\Member;
use App\Notifications\Auth\LoginCodeNotification;
use Filament\Widgets\Concerns\CanPoll;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Support\WebAuthn;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use ReflectionClass;
use ReflectionMethod;
use Tests\Concerns\CapturesSecurityLog;
use Tests\Support\FakeAuthenticator;
use Tests\TestCase;
use Webauthn\PublicKeyCredential;

class AdminEntryTest extends TestCase
{
    use CapturesSecurityLog;
    use RefreshDatabase;

    private const DOCUMENT = ['Sec-Fetch-Dest' => 'document', 'Sec-Fetch-Mode' => 'navigate'];

    private const FETCH = ['Sec-Fetch-Dest' => 'empty', 'Sec-Fetch-Mode' => 'cors'];

    private function signedIn(?Member $admin = null): Member
    {
        $admin ??= $this->createAdmin();
        $this->actingAs($admin, 'member');

        return $admin;
    }

    private function withTotp(Member $member): Member
    {
        app(EnableTwoFactorAuthentication::class)($member, force: true);
        $member->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $member->fresh();
    }

    private function otp(Member $member): string
    {
        return app(Google2FA::class)->getCurrentOtp(decrypt($member->two_factor_secret));
    }

    public function test_a_page_visit_without_the_entry_goes_to_it_and_comes_back(): void
    {
        $this->signedIn();

        $this->get('/admin/members?page=2', self::DOCUMENT)->assertRedirect(route('member.admin_entry'));
        $this->assertSame(url('/admin/members?page=2'), session('url.intended'));

        $this->get(route('member.admin_entry'))->assertOk()->assertSee(__('Open the admin panel'));
        $this->post(route('member.admin_entry.store'), ['current_password' => 'password'])
            ->assertRedirect(url('/admin/members?page=2'));

        $this->get('/admin/members', self::DOCUMENT)->assertOk();
    }

    public function test_the_entry_lasts_the_session_lifetime_from_the_last_panel_request(): void
    {
        config(['session.lifetime' => 120]);
        $this->freezeSecond();
        $this->actingAsAdmin();

        $this->travel(120)->minutes();
        $this->get('/admin')->assertOk();
        $this->travel(119)->minutes();
        $this->get('/admin')->assertOk();

        // A member page does not keep the panel's entry open.
        $this->travel(100)->minutes();
        $this->get('/')->assertOk();
        $this->travel(21)->minutes();
        $this->get('/admin')->assertRedirect(route('member.admin_entry'));
    }

    public function test_a_mark_from_beyond_a_clock_skew_in_the_future_opens_nothing(): void
    {
        $this->freezeSecond();
        $this->actingAsAdmin();

        $this->travel(-60)->seconds();
        $this->get('/admin')->assertOk();

        $this->travel(-61)->seconds();
        $this->get('/admin')->assertRedirect(route('member.admin_entry'));
    }

    public function test_a_livewire_update_after_the_lapse_moves_the_window_to_the_entry_and_back_to_the_last_page(): void
    {
        $this->signedIn();

        $this->withSession(['_previous' => ['url' => url('/admin/members')]])
            ->post(EndpointResolver::updatePath(), ['components' => []], self::FETCH + ['X-Livewire' => '1'])
            ->assertRedirect(route('member.admin_entry'));
        $this->assertSame(url('/admin/members'), session('url.intended'));

        // The last page was a member one, so the way back is the panel's front.
        $this->withSession(['_previous' => ['url' => url('/diary')]])
            ->post(EndpointResolver::updatePath(), ['components' => []], self::FETCH + ['X-Livewire' => '1'])
            ->assertRedirect(route('member.admin_entry'));
        $this->assertSame(url('/admin'), session('url.intended'));
    }

    /** @return array<string, array{string, string, array<string, string>}> */
    public static function backgroundRequests(): array
    {
        return [
            'a livewire upload' => ['POST', 'upload', self::FETCH],
            'a livewire preview' => ['GET', 'preview', ['Sec-Fetch-Dest' => 'image']],
            'an uploaded file' => ['GET', '/admin/file/x/raw', ['Sec-Fetch-Dest' => 'image']],
            'the panel language' => ['POST', '/admin/locale/session', self::FETCH],
            'the site icon' => ['POST', '/admin/site-icon/raster', self::FETCH],
            // Such a browser's upload XHR would otherwise pass for a page visit.
            'an upload without Fetch Metadata' => ['POST', 'upload', []],
            'an export download' => ['GET', '/filament/exports/1/download', self::FETCH],
        ];
    }

    /** @param  array<string, string>  $headers */
    #[DataProvider('backgroundRequests')]
    public function test_a_request_that_is_no_page_visit_is_refused_after_the_lapse(string $method, string $uri, array $headers): void
    {
        // The Livewire paths derive from the app key, which a data provider runs before.
        $uri = match ($uri) {
            'upload' => EndpointResolver::uploadPath(),
            'preview' => str_replace('{filename}', 'x.png', EndpointResolver::previewPath()),
            default => $uri,
        };
        $this->signedIn();

        $this->call($method, $uri, [], [], [], $this->transformHeadersToServerVars($headers))->assertForbidden();
        $this->assertNull(session('url.intended'));
    }

    public function test_an_export_download_opened_as_a_page_comes_back_to_the_download(): void
    {
        $this->signedIn();

        $this->get('/filament/exports/1/download', self::DOCUMENT)->assertRedirect(route('member.admin_entry'));
        $this->assertSame(url('/filament/exports/1/download'), session('url.intended'));
    }

    public function test_the_entry_stands_only_for_the_member_generation_and_password_it_was_proven_with(): void
    {
        $admin = $this->createAdmin();
        $other = $this->createAdmin();
        $session = app('session.store');

        AdminEntry::stamp($session, $admin);
        $this->assertTrue(AdminEntry::holds($session, $admin));
        $this->assertFalse(AdminEntry::holds($session, $other));

        $admin->forceFill(['admin_generation' => $admin->admin_generation + 1])->save();
        $this->assertFalse(AdminEntry::holds($session, $admin));

        AdminEntry::stamp($session, $admin);
        $admin->forceFill(['password' => Hash::make('another-password')])->save();
        $this->assertFalse(AdminEntry::holds($session, $admin));
    }

    public function test_another_administrators_entry_on_the_session_opens_nothing(): void
    {
        $other = $this->createAdmin();
        $this->signedIn();
        $this->withSession([AdminEntry::SESSION_KEY => AdminEntry::mark($other)]);

        $this->get('/admin')->assertRedirect(route('member.admin_entry'));
    }

    public function test_the_entry_screen_is_only_for_an_administrator(): void
    {
        $this->get(route('member.admin_entry'))->assertRedirect(route('login'));

        $this->actingAs(Member::factory()->create(), 'member');
        $this->get(route('member.admin_entry'))->assertForbidden();
        $this->post(route('member.admin_entry.store'), ['current_password' => 'password'])->assertForbidden();
        $this->assertNull(session(AdminEntry::SESSION_KEY));
    }

    public function test_the_entry_screen_has_a_modern_form(): void
    {
        config()->set('openpne.surface_mode', 'modern_default');
        $this->signedIn();

        $this->get(route('member.admin_entry'))
            ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/admin-entry')->has('csrfToken'));
    }

    public function test_the_entry_screen_with_a_live_entry_goes_on_to_the_panel(): void
    {
        $this->actingAsAdmin()->withSession(['url.intended' => url('/diary')]);

        $this->get(route('member.admin_entry'))->assertRedirect(url('/admin'));

        $host = (string) parse_url(url('/'), PHP_URL_HOST);
        $this->withSession(['url.intended' => str_replace($host, str_repeat('x', strlen($host)), url('/admin/members'))]);
        $this->get(route('member.admin_entry'))->assertRedirect(url('/admin'));
    }

    public function test_a_proof_regenerates_the_session_and_is_logged(): void
    {
        $this->captureSecurityLog();
        $admin = $this->signedIn();
        $this->post(route('member.admin_entry.store'), ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->assertNull(session(AdminEntry::SESSION_KEY));
        [$before, $token] = [session()->getId(), session()->token()];

        $this->withCookie(config('session.cookie'), $before)
            ->post(route('member.admin_entry.store'), ['current_password' => 'password'])->assertRedirect(url('/admin'));
        $this->assertNotSame($before, session()->getId());
        $this->assertSame($token, session()->token());
        $context = $this->assertOneSecurityEvent('admin.entered');
        $this->assertSame([(string) $admin->getKey(), 'password'], [$context['member_id'], $context['via']]);
    }

    public function test_an_administrator_with_totp_is_asked_for_the_code_too(): void
    {
        $this->signedIn($admin = $this->withTotp($this->createAdmin()));

        $this->post(route('member.admin_entry.store'), ['current_password' => 'password'])->assertSessionHasErrors('code');
        $this->post(route('member.admin_entry.store'), ['current_password' => 'password', 'code' => $this->otp($admin)])
            ->assertRedirect(url('/admin'));
    }

    public function test_a_passkey_proof_made_for_the_entry_opens_it(): void
    {
        $this->captureSecurityLog();
        $admin = $this->signedIn($this->withTotp($this->createAdmin()));

        PasskeyProof::issue(app('session.store'), $admin, ReauthPurpose::PasswordChange);
        $this->post(route('member.admin_entry.store'))->assertSessionHasErrors('current_password');

        PasskeyProof::issue(app('session.store'), $admin, ReauthPurpose::AdminEntry);
        $this->post(route('member.admin_entry.store'))->assertRedirect(url('/admin'));
        $this->assertSame('passkey', $this->assertOneSecurityEvent('admin.entered')['via']);
    }

    public function test_a_passkey_proof_given_before_the_role_or_password_changed_opens_nothing(): void
    {
        $member = $this->signedIn(Member::factory()->create());
        PasskeyProof::issue(app('session.store'), $member, ReauthPurpose::AdminEntry);
        $member->forceFill(['admin_granted_at' => now(), 'admin_generation' => $member->admin_generation + 1])->save();
        $this->post(route('member.admin_entry.store'))->assertSessionHasErrors('current_password');

        $this->assertNull(session(AdminEntry::SESSION_KEY));

        // A new password also closes the session on its next request, so the proof is read directly.
        PasskeyProof::issue(app('session.store'), $member, ReauthPurpose::AdminEntry);
        $member->forceFill(['password' => Hash::make('another-password')])->save();
        $this->assertFalse(PasskeyProof::stands(app('session.store'), $member, ReauthPurpose::AdminEntry));
    }

    public function test_a_login_headed_for_the_panel_opens_its_entry(): void
    {
        $this->captureSecurityLog();
        $admin = $this->createAdmin();

        $this->get('/admin/members')->assertRedirect(route('login'));
        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect(url('/admin/members'));

        $this->get('/admin/members')->assertOk();
        $this->assertSame('login', $this->assertOneSecurityEvent('admin.entered')['via']);
    }

    public function test_a_login_through_totp_headed_for_the_panel_opens_its_entry(): void
    {
        $admin = $this->withTotp($this->createAdmin());

        $this->get('/admin')->assertRedirect(route('login'));
        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect('/two-factor-challenge');
        $this->assertNull(session(AdminEntry::SESSION_KEY));
        $this->post('/two-factor-challenge', ['code' => $this->otp($admin)])->assertRedirect(url('/admin'));

        $this->get('/admin')->assertOk();
    }

    public function test_a_login_headed_for_the_panel_marks_nothing_for_a_member(): void
    {
        $member = Member::factory()->create();

        $this->get('/admin')->assertRedirect(route('login'));
        $this->post('/login', ['email' => $member->email, 'password' => 'password']);

        $this->assertNull(session(AdminEntry::SESSION_KEY));
    }

    public function test_a_login_headed_elsewhere_leaves_the_entry_asking(): void
    {
        $admin = $this->createAdmin();

        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect('/');

        $this->get('/admin')->assertRedirect(route('member.admin_entry'));
    }

    public function test_a_login_through_the_mailed_code_headed_for_the_panel_opens_its_entry(): void
    {
        Notification::fake();
        $admin = $this->createAdmin();
        $this->registeredPasskey($admin);

        $this->get('/admin')->assertRedirect(route('login'));
        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect('/login/code');
        $this->assertNull(session(AdminEntry::SESSION_KEY));

        $code = null;
        Notification::assertSentOnDemand(LoginCodeNotification::class, function (LoginCodeNotification $n) use (&$code): bool {
            $code = $n->code;

            return true;
        });
        $this->post('/login/code', ['code' => $code])->assertRedirect(url('/admin'));

        $this->get('/admin')->assertOk();
    }

    public function test_a_passkey_login_headed_for_the_panel_opens_its_entry(): void
    {
        $admin = $this->createAdmin();
        $authenticator = $this->registeredPasskey($admin);

        $this->get('/admin')->assertRedirect(route('login'));
        $options = $this->getJson('/passkeys/login/options')->assertOk()->json('options');
        $this->postJson('/passkeys/login', ['credential' => $authenticator->assert($options)])
            ->assertOk()->assertJsonPath('redirect', url('/admin'));

        $this->get('/admin')->assertOk();
    }

    public function test_nothing_in_the_panel_polls(): void
    {
        $polling = [];
        foreach (File::allFiles(app_path()) as $file) {
            $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            if (class_exists($class) && ! (new ReflectionClass($class))->isAbstract() && in_array(CanPoll::class, class_uses_recursive($class), true)) {
                $polling[] = $class;
                $this->assertNull((new ReflectionMethod($class, 'getPollingInterval'))->invoke(app($class)), $class);
            }
        }
        $this->assertContains(RegistrationModeWidget::class, $polling);

        foreach ([app_path(), resource_path('views')] as $root) {
            foreach (File::allFiles($root) as $file) {
                $this->assertDoesNotMatchRegularExpression('/->poll\(|wire:poll|databaseNotifications/', $file->getContents(), $file->getRelativePathname());
            }
        }
    }

    private function registeredPasskey(Member $member): FakeAuthenticator
    {
        $authenticator = FakeAuthenticator::forApp();
        $options = app(GenerateRegistrationOptions::class)($member);
        $credential = WebAuthn::fromJson(json_encode($authenticator->attest(WebAuthn::toBrowserArray($options))), PublicKeyCredential::class);
        app(StorePasskey::class)($member, 'phone', $credential, $options);

        return $authenticator;
    }
}
