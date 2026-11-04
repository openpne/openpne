<?php

namespace Tests\Feature\Auth\Listeners;

use App\Models\AdminUser;
use App\Models\Member;
use App\Models\RegistrationToken;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Support\WebAuthn;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;
use Tests\Support\FakeAuthenticator;
use Tests\TestCase;
use Webauthn\PublicKeyCredential;

class RecordLastLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_password_login_records_the_time(): void
    {
        $member = Member::factory()->create();
        $this->assertNull($member->last_login_at);

        $this->travel(3)->hours();
        $this->post('/login', ['email' => $member->email, 'password' => 'password']);

        $this->assertAuthenticatedAs($member);
        $this->assertSame(now()->toDateTimeString(), $member->fresh()->last_login_at->toDateTimeString());
    }

    public function test_the_write_leaves_updated_at_alone(): void
    {
        // Through the event directly: the password route also rehashes, which moves updated_at on its own.
        $member = Member::factory()->create()->fresh();
        $updatedAt = $member->updated_at;

        $this->travel(3)->hours();
        event(new Login('member', $member, false));

        $fresh = $member->fresh();
        $this->assertSame(now()->toDateTimeString(), $fresh->last_login_at->toDateTimeString());
        $this->assertTrue($fresh->updated_at->equalTo($updatedAt));
    }

    public function test_a_two_factor_login_records_the_time_at_the_challenge_not_the_password_step(): void
    {
        $member = Member::factory()->create();
        app(EnableTwoFactorAuthentication::class)($member, force: true);
        $member->forceFill(['two_factor_confirmed_at' => now()])->save();
        $member = $member->fresh();

        $this->post('/login', ['email' => $member->email, 'password' => 'password'])->assertRedirect('/two-factor-challenge');
        $this->assertGuest();
        $this->assertNull($member->fresh()->last_login_at);

        $code = app(Google2FA::class)->getCurrentOtp(decrypt($member->two_factor_secret));
        $this->post('/two-factor-challenge', ['code' => $code]);

        $this->assertAuthenticatedAs($member);
        $this->assertNotNull($member->fresh()->last_login_at);
    }

    public function test_a_passkey_login_records_the_time(): void
    {
        $member = Member::factory()->create();
        $authenticator = $this->registeredPasskey($member);

        $this->postJson('/passkeys/login', ['credential' => $authenticator->assert($this->loginOptions())])->assertOk();

        $this->assertAuthenticatedAs($member);
        $this->assertNotNull($member->fresh()->last_login_at);
    }

    public function test_a_refused_passkey_assertion_records_nothing(): void
    {
        $member = Member::factory()->create(['is_login_rejected' => true]);
        $authenticator = $this->registeredPasskey($member);

        $this->postJson('/passkeys/login', ['credential' => $authenticator->assert($this->loginOptions())])->assertUnprocessable();

        $this->assertGuest();
        $this->assertNull($member->fresh()->last_login_at);
    }

    public function test_the_login_that_completes_registration_records_the_time(): void
    {
        $raw = Str::random(40);
        RegistrationToken::create(['email' => 'newcomer@example.com', 'token' => hash('sha256', $raw), 'created_at' => now()]);

        $this->post("/register/{$raw}", ['name' => 'Newcomer', 'password' => 'sufficiently-long-pw', 'password_confirmation' => 'sufficiently-long-pw'])
            ->assertRedirect('/');

        $this->assertAuthenticated();
        $this->assertNotNull(Member::where('email', 'newcomer@example.com')->sole()->last_login_at);
    }

    public function test_a_remember_me_restore_records_the_time_as_a_login(): void
    {
        $member = Member::factory()->create();
        $recallerName = auth()->guard('member')->getRecallerName();
        $recaller = $this->post('/login', ['email' => $member->email, 'password' => 'password', 'remember' => '1'])
            ->getCookie($recallerName);
        $this->assertNotNull($recaller);

        // A later visit from a browser whose session has expired: no session, only the recaller cookie.
        Member::withoutTimestamps(fn () => $member->forceFill(['last_login_at' => null])->saveQuietly());
        $this->freshRequestState();
        $seen = [];
        Event::listen(Login::class, function (Login $event) use (&$seen): void {
            $seen[] = $event;
        });

        // getCookie() hands back the decrypted value; withCookie() encrypts it again for the request.
        $this->withCookie($recallerName, $recaller->getValue())->get('/');

        $this->assertAuthenticatedAs($member);
        $this->assertNotNull($member->fresh()->last_login_at);
        $this->assertCount(1, $seen);
        $this->assertTrue($seen[0]->remember);
    }

    public function test_a_session_in_use_refreshes_the_time_once_a_day(): void
    {
        $member = Member::factory()->create();
        $this->post('/login', ['email' => $member->email, 'password' => 'password']);
        $loggedInAt = $member->fresh()->last_login_at;

        // Same session, a later request: the guard resolves the member from the session, no Login fires.
        $this->travel(23)->hours();
        app('auth')->forgetGuards();
        $this->get('/')->assertOk();
        $this->assertTrue($member->fresh()->last_login_at->equalTo($loggedInAt));

        $this->travel(2)->hours();
        app('auth')->forgetGuards();
        $this->get('/')->assertOk();
        $this->assertSame(now()->toDateTimeString(), $member->fresh()->last_login_at->toDateTimeString());
    }

    public function test_a_member_never_recorded_is_recorded_on_first_authenticated_request(): void
    {
        $member = Member::factory()->create();

        event(new Authenticated('member', $member));

        $this->assertNotNull($member->fresh()->last_login_at);
    }

    public function test_a_failed_write_is_reported_and_does_not_block_the_login(): void
    {
        $member = Member::factory()->create();
        // Injected at the query layer: DDL would auto-commit on MySQL and leak past RefreshDatabase.
        DB::connection()->beforeExecuting(function (string $query): void {
            if (str_contains($query, 'last_login_at')) {
                throw new RuntimeException('last_login_at write refused');
            }
        });
        $reported = [];
        $this->app[ExceptionHandler::class]->reportable(function (RuntimeException $e) use (&$reported): void {
            $reported[] = $e->getMessage();
        });

        $this->post('/login', ['email' => $member->email, 'password' => 'password'])->assertRedirect('/');

        $this->assertAuthenticatedAs($member);
        // More than once: the Authenticated retry only happens if the refused write left the model untouched.
        $this->assertSame(['last_login_at write refused'], array_values(array_unique($reported)));
        $this->assertGreaterThanOrEqual(2, count($reported));
        $this->assertNull($member->fresh()->last_login_at);
    }

    public function test_a_member_logging_in_on_another_guard_is_not_recorded(): void
    {
        $member = Member::factory()->create();

        event(new Login('admin', $member, false));

        $this->assertNull($member->fresh()->last_login_at);
    }

    public function test_a_non_member_logging_in_on_the_member_guard_is_not_recorded(): void
    {
        $member = Member::factory()->create();

        event(new Login('member', AdminUser::factory()->create(), false));

        $this->assertNull($member->fresh()->last_login_at);
    }

    private function registeredPasskey(Member $member): FakeAuthenticator
    {
        $authenticator = FakeAuthenticator::forApp();
        $options = app(GenerateRegistrationOptions::class)($member);
        $credential = WebAuthn::fromJson(json_encode($authenticator->attest(WebAuthn::toBrowserArray($options))), PublicKeyCredential::class);
        app(StorePasskey::class)($member, 'phone', $credential, $options);

        return $authenticator;
    }

    /** @return array<string, mixed> */
    private function loginOptions(): array
    {
        return $this->getJson('/passkeys/login/options')->assertOk()->json('options');
    }
}
