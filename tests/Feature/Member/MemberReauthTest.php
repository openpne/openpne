<?php

namespace Tests\Feature\Member;

use App\Auth\Reauth\VerifyReauthProof;
use App\Features\Member\Actions\DisableMemberMfa;
use App\Features\Member\Actions\RegenerateMemberRecoveryCodes;
use App\Features\Member\PasskeyReauth;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Passkeys\Passkey;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class MemberReauthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function memberWithTwoFactor(): Member
    {
        $member = Member::factory()->create();
        app(EnableTwoFactorAuthentication::class)($member, force: true);
        $member->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $member->fresh();
    }

    private function currentOtp(Member $member): string
    {
        return app(Google2FA::class)->getCurrentOtp(decrypt($member->two_factor_secret));
    }

    private function passkeyFor(Member $member): Passkey
    {
        return Passkey::forceCreate([
            'user_id' => $member->getKey(),
            'name' => 'Test',
            'credential_id' => 'cred-'.$member->getKey(),
            'credential' => [],
        ]);
    }

    /** Counts verifications, since Fortify's replay cache fails any second check of the same code. */
    private function countTotpVerifications(): object
    {
        $inner = app(TwoFactorAuthenticationProvider::class);
        $spy = new class($inner) implements TwoFactorAuthenticationProvider
        {
            public int $calls = 0;

            public function __construct(private readonly TwoFactorAuthenticationProvider $inner) {}

            public function generateSecretKey($secretLength = 16)
            {
                return $this->inner->generateSecretKey($secretLength);
            }

            public function qrCodeUrl($companyName, $companyEmail, $secret)
            {
                return $this->inner->qrCodeUrl($companyName, $companyEmail, $secret);
            }

            public function verify($secret, $code)
            {
                $this->calls++;

                return $this->inner->verify($secret, $code);
            }
        };
        $this->app->instance(TwoFactorAuthenticationProvider::class, $spy);

        return $spy;
    }

    /** @return array<string, array{string, string, bool}> */
    public static function secondFactorOperations(): array
    {
        return [
            'passkey add' => ['post', '/member/config/passkeys/reauth', true],
            'passkey remove' => ['delete', '/member/config/passkeys/{passkey}', true],
            'mfa disable' => ['post', '/member/config/mfa/disable', true],
            'recovery codes' => ['post', '/member/config/mfa/recovery-codes', false],
        ];
    }

    #[DataProvider('secondFactorOperations')]
    public function test_a_confirmed_factor_is_asked_for_with_the_password(string $method, string $uri, bool $recoveryAllowed): void
    {
        $member = $this->memberWithTwoFactor();
        $uri = str_replace('{passkey}', (string) $this->passkeyFor($member)->getKey(), $uri);
        $spy = $this->countTotpVerifications();

        $this->actingAs($member)->{$method}($uri, ['current_password' => 'password'])
            ->assertSessionHasErrors('code');
        $this->actingAs($member)->{$method}($uri, ['current_password' => 'password', 'code' => '000000'])
            ->assertSessionHasErrors('code');
        $this->assertSame(1, $spy->calls);

        // A wrong password stops before the code is looked at, so the code stays usable.
        $otp = $this->currentOtp($member);
        $this->actingAs($member)->{$method}($uri, ['current_password' => 'wrong-password', 'code' => $otp])
            ->assertSessionHasErrors('current_password');
        $this->assertSame(1, $spy->calls);

        $this->actingAs($member)->{$method}($uri, ['current_password' => 'password', 'code' => $otp])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, $spy->calls);
    }

    #[DataProvider('secondFactorOperations')]
    public function test_a_recovery_code_stands_in_only_where_the_operation_allows_it(string $method, string $uri, bool $recoveryAllowed): void
    {
        $member = $this->memberWithTwoFactor();
        $uri = str_replace('{passkey}', (string) $this->passkeyFor($member)->getKey(), $uri);
        $recovery = $member->recoveryCodes()[0];

        $response = $this->actingAs($member)->{$method}($uri, ['current_password' => 'password', 'recovery_code' => $recovery]);

        if (! $recoveryAllowed) {
            $response->assertSessionHasErrors('code');
            $this->assertContains($recovery, $member->fresh()->recoveryCodes());

            return;
        }

        $response->assertSessionHasNoErrors();
        $fresh = $member->fresh();
        if ($fresh->two_factor_recovery_codes === null) {
            $this->assertNull($fresh->two_factor_confirmed_at);

            return;
        }
        $this->assertNotContains($recovery, $fresh->recoveryCodes());
    }

    public function test_regenerating_ignores_a_recovery_code_sent_beside_a_wrong_code(): void
    {
        $member = $this->memberWithTwoFactor();
        $codes = $member->recoveryCodes();

        $this->actingAs($member)
            ->post('/member/config/mfa/recovery-codes', ['current_password' => 'password', 'code' => '000000', 'recovery_code' => $codes[0]])
            ->assertSessionHasErrors('code');

        $this->assertSame($codes, $member->fresh()->recoveryCodes());
    }

    public function test_a_member_without_a_confirmed_factor_removes_a_passkey_on_the_password_alone(): void
    {
        $member = Member::factory()->create();
        $passkey = $this->passkeyFor($member);

        $this->actingAs($member)
            ->delete("/member/config/passkeys/{$passkey->getKey()}", ['current_password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, Passkey::count());
    }

    public function test_a_password_changed_after_the_rule_passed_does_not_prove_the_request(): void
    {
        $member = Member::factory()->create();

        // The rule read the session's instance; the row changes before the verifier locks it.
        $this->app->bind(VerifyReauthProof::class, function () use ($member) {
            $member->fresh()->forceFill(['password' => Hash::make('changed-meanwhile')])->save();

            return new VerifyReauthProof;
        });

        $this->actingAs($member)
            ->post('/member/config/passkeys/reauth', ['current_password' => 'password'])
            ->assertSessionHasErrors('current_password');
        $this->assertFalse(PasskeyReauth::isFresh(app('session.store')));
    }

    public function test_a_factor_confirmed_after_the_rules_ran_does_not_remove_a_passkey(): void
    {
        $member = Member::factory()->create();
        $passkey = $this->passkeyFor($member);

        $this->app->bind(VerifyReauthProof::class, function () use ($member) {
            $row = $member->fresh();
            app(EnableTwoFactorAuthentication::class)($row, force: true);
            $row->forceFill(['two_factor_confirmed_at' => now()])->save();

            return new VerifyReauthProof;
        });

        $this->actingAs($member)
            ->delete("/member/config/passkeys/{$passkey->getKey()}", ['current_password' => 'password'])
            ->assertSessionHasErrors('current_password');
        $this->assertSame(1, Passkey::count());
    }

    public function test_removing_a_passkey_that_is_gone_spends_no_recovery_code(): void
    {
        $member = $this->memberWithTwoFactor();
        $codes = $member->recoveryCodes();

        $this->actingAs($member)
            ->delete('/member/config/passkeys/999999', ['current_password' => 'password', 'recovery_code' => $codes[0]])
            ->assertNotFound();

        $this->assertSame($codes, $member->fresh()->recoveryCodes());
    }

    public function test_a_password_changed_after_the_rule_passed_does_not_disable_the_factor(): void
    {
        $member = $this->memberWithTwoFactor();
        $otp = $this->currentOtp($member);
        $spy = $this->countTotpVerifications();

        $this->app->bind(DisableMemberMfa::class, function ($app) use ($member) {
            $member->fresh()->forceFill(['password' => Hash::make('changed-meanwhile')])->save();

            return new DisableMemberMfa($app->make(DisableTwoFactorAuthentication::class));
        });

        $this->actingAs($member)
            ->post('/member/config/mfa/disable', ['current_password' => 'password', 'code' => $otp])
            ->assertSessionHasErrors('current_password');

        $this->assertNotNull($member->fresh()->two_factor_confirmed_at);
        $this->assertSame(0, $spy->calls);
    }

    public function test_a_password_changed_after_the_rule_passed_does_not_regenerate_the_codes(): void
    {
        $member = $this->memberWithTwoFactor();
        $codes = $member->recoveryCodes();

        $this->app->bind(RegenerateMemberRecoveryCodes::class, function ($app) use ($member) {
            $member->fresh()->forceFill(['password' => Hash::make('changed-meanwhile')])->save();

            return new RegenerateMemberRecoveryCodes($app->make(GenerateNewRecoveryCodes::class));
        });

        $this->actingAs($member)
            ->post('/member/config/mfa/recovery-codes', ['current_password' => 'password', 'code' => $this->currentOtp($member)])
            ->assertSessionHasErrors('current_password');

        $this->assertSame($codes, $member->fresh()->recoveryCodes());
    }

    public function test_the_classic_remove_form_asks_a_totp_member_for_the_code(): void
    {
        $member = $this->memberWithTwoFactor();
        $passkey = $this->passkeyFor($member);

        $this->actingAs($member)->get('/member/config?category=passkey')
            ->assertOk()
            ->assertSee('id="passkey_delete_'.$passkey->getKey().'_code"', false)
            ->assertSee('id="passkey_delete_'.$passkey->getKey().'_recovery_code"', false);

        $plain = Member::factory()->create();
        $other = $this->passkeyFor($plain);
        $this->actingAs($plain)->get('/member/config?category=passkey')
            ->assertOk()
            ->assertSee('id="passkey_delete_'.$other->getKey().'_password"', false)
            ->assertDontSee('id="passkey_delete_'.$other->getKey().'_code"', false);
    }

    public function test_a_classic_code_error_reopens_the_remove_form_it_was_sent_from(): void
    {
        $member = $this->memberWithTwoFactor();
        $passkey = $this->passkeyFor($member);
        $id = $passkey->getKey();

        $this->actingAs($member)
            ->from('/member/config?category=passkey')
            ->delete("/member/config/passkeys/{$id}", ['current_password' => 'password', 'code' => '000000', '_passkey_form' => "destroy-{$id}"])
            ->assertRedirect('/member/config?category=passkey');

        $this->get('/member/config?category=passkey')
            ->assertSee('<details  open >', false)
            ->assertSeeInOrder(['id="passkey_delete_'.$id.'_code"', 'The provided two factor authentication code was invalid.', 'id="passkey_add_password"'], false);
    }
}
