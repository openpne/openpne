<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp\OAuth;

use App\Features\AiAccount\AiTokenReauth;
use App\Mcp\McpAbilities;
use App\Mcp\OAuth\ConnectionReauth;
use App\Mcp\OAuth\ConsentReauth;
use App\Models\Member;
use App\Support\SnsSettingKey;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Passkeys\Passkey;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;

/** Every member step-up in one table, so one that leaves the seam shows up as a row that passes with less. */
class ReauthCoverageTest extends OAuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setSnsSetting(SnsSettingKey::AiAccountsEnabled, true);
        Notification::fake();
        // Withdrawal refuses member id 1.
        Member::factory()->create();
    }

    private function member(string $factor): Member
    {
        $member = Member::factory()->create();
        if ($factor !== 'none') {
            app(EnableTwoFactorAuthentication::class)($member, force: true);
        }
        if ($factor === 'confirmed') {
            $member->forceFill(['two_factor_confirmed_at' => now()])->save();
        }

        return $member->fresh();
    }

    private function otp(Member $member): string
    {
        return app(Google2FA::class)->getCurrentOtp(decrypt($member->two_factor_secret));
    }

    /**
     * Builds the request afresh each time, since several operations spend what they point at.
     *
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private function operation(string $key, Member $member): array
    {
        $this->actingAs($member, 'member');
        $ai = fn (): Member => Member::factory()->aiAccount($member)->create();

        return match ($key) {
            'mfa enable' => ['post', '/member/config/mfa/enable', []],
            'mfa confirm' => ['post', '/member/config/mfa/confirm', ['code' => $this->otp($member)]],
            'mfa disable' => ['post', '/member/config/mfa/disable', []],
            'recovery codes' => ['post', '/member/config/mfa/recovery-codes', []],
            'passkey add' => ['post', '/member/config/passkeys/reauth', []],
            'passkey remove' => ['delete', '/member/config/passkeys/'.Passkey::forceCreate(['user_id' => $member->getKey(), 'name' => 'Test', 'credential_id' => Str::random(20), 'credential' => []])->getKey(), []],
            'password change' => ['post', '/member/config/password', ['password' => 'new-secret-pass', 'password_confirmation' => 'new-secret-pass']],
            'email change' => ['post', '/member/config/email', ['new_email' => Str::random(8).'@example.com']],
            'withdrawal' => ['post', '/member/config/withdrawal', ['confirm' => '1']],
            'ai account delete' => ['post', "/member/config/ai/{$ai()->getKey()}/delete", []],
            'ai token mint' => ['post', "/member/config/ai/{$ai()->getKey()}/tokens", []],
            'ai token revoke' => (function () use ($ai): array {
                $account = $ai();
                $token = $account->createToken(McpAbilities::TOKEN_NAME, [McpAbilities::READ])->accessToken;

                return ['post', "/member/config/ai/{$account->getKey()}/tokens/{$token->getKey()}/delete", []];
            })(),
            'oauth consent' => (function (): array {
                $client = $this->registerClient();
                [, $challenge] = $this->pkce();
                $screen = $this->get($this->authorizeUrl($client['client_id'], $challenge))->assertOk();

                return ['post', '/oauth/authorize', ['auth_token' => $screen->inertiaPage()['props']['authToken'], 'actor' => 'self']];
            })(),
            'connection end' => (function () use ($member): array {
                $client = Client::factory()->asPublic()->create(['name' => 'Claude', 'redirect_uris' => [self::REDIRECT]]);
                $access = Passport::token()->forceFill([
                    'id' => Str::random(80), 'user_id' => $member->getKey(), 'client_id' => $client->getKey(),
                    'scopes' => ['mcp:use'], 'revoked' => false, 'expires_at' => now()->addHour(),
                ]);
                $access->save();

                return ['post', "/member/config/connections/{$client->getKey()}/{$member->getKey()}/delete", []];
            })(),
        };
    }

    /** @return array<string, array{string, string}> the operation and the factor state it needs */
    public static function operations(): array
    {
        return [
            'mfa enable' => ['mfa enable', 'none'],
            'mfa confirm' => ['mfa confirm', 'pending'],
            'mfa disable' => ['mfa disable', 'confirmed'],
            'recovery codes' => ['recovery codes', 'confirmed'],
            'passkey add' => ['passkey add', 'none'],
            'passkey remove' => ['passkey remove', 'none'],
            'password change' => ['password change', 'none'],
            'email change' => ['email change', 'none'],
            'withdrawal' => ['withdrawal', 'none'],
            'ai account delete' => ['ai account delete', 'none'],
            'ai token mint' => ['ai token mint', 'none'],
            'ai token revoke' => ['ai token revoke', 'none'],
            'oauth consent' => ['oauth consent', 'none'],
            'connection end' => ['connection end', 'none'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function secondFactorOperations(): array
    {
        return array_map(
            fn (array $row): array => [$row[0]],
            array_diff_key(self::operations(), ['mfa enable' => 0, 'mfa confirm' => 0]),
        );
    }

    /** @return array<string, array{string, class-string}> */
    public static function windowedOperations(): array
    {
        return [
            'ai token mint' => ['ai token mint', AiTokenReauth::class],
            'oauth consent' => ['oauth consent', ConsentReauth::class],
            'connection end' => ['connection end', ConnectionReauth::class],
        ];
    }

    #[DataProvider('operations')]
    public function test_nothing_passes_without_the_password(string $key, string $factor): void
    {
        $member = $this->member($factor);

        [$method, $uri, $form] = $this->operation($key, $member);
        $this->{$method}($uri, $form)->assertSessionHasErrors('current_password');

        [$method, $uri, $form] = $this->operation($key, $member);
        $this->{$method}($uri, $form + ['current_password' => 'wrong-password'])->assertSessionHasErrors('current_password');

        [$method, $uri, $form] = $this->operation($key, $member);
        $proof = ['current_password' => 'password'] + ($factor === 'confirmed' ? ['code' => $this->otp($member)] : []);
        $this->{$method}($uri, $form + $proof)->assertSessionHasNoErrors()->assertRedirect();
    }

    #[DataProvider('secondFactorOperations')]
    public function test_a_confirmed_factor_is_asked_for_beside_the_password(string $key): void
    {
        $member = $this->member('confirmed');

        [$method, $uri, $form] = $this->operation($key, $member);
        $this->{$method}($uri, $form + ['current_password' => 'password'])->assertSessionHasErrors('code');

        [$method, $uri, $form] = $this->operation($key, $member);
        $this->{$method}($uri, $form + ['current_password' => 'password', 'code' => '000000'])->assertSessionHasErrors('code');

        [$method, $uri, $form] = $this->operation($key, $member);
        $this->{$method}($uri, $form + ['current_password' => 'password', 'code' => $this->otp($member)])->assertSessionHasNoErrors()->assertRedirect();
    }

    #[DataProvider('windowedOperations')]
    public function test_a_window_opens_on_a_verified_proof_alone_and_lapses(string $key, string $window): void
    {
        $member = $this->member('confirmed');

        [$method, $uri, $form] = $this->operation($key, $member);
        $this->{$method}($uri, $form + ['current_password' => 'password', 'code' => '000000'])->assertSessionHasErrors('code');
        $this->assertFalse($window::isFresh(app('session.store')));

        [$method, $uri, $form] = $this->operation($key, $member);
        $this->{$method}($uri, $form + ['current_password' => 'password', 'code' => $this->otp($member)])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue($window::isFresh(app('session.store')));

        [$method, $uri, $form] = $this->operation($key, $member);
        $this->{$method}($uri, $form)->assertSessionHasNoErrors();

        // Still verified when sent, even though the window no longer asks for it.
        [$method, $uri, $form] = $this->operation($key, $member);
        $this->{$method}($uri, $form + ['current_password' => 'wrong-password'])->assertSessionHasErrors('current_password');

        // A proof sent inside the window is verified but does not push the window's end back.
        $this->travel(10)->minutes();
        [$method, $uri, $form] = $this->operation($key, $member);
        $this->{$method}($uri, $form + ['current_password' => 'password', 'code' => $this->otp($member)])->assertSessionHasNoErrors();

        $this->travel(6)->minutes();
        [$method, $uri, $form] = $this->operation($key, $member);
        $this->{$method}($uri, $form)->assertSessionHasErrors('current_password');
    }

    #[DataProvider('windowedOperations')]
    public function test_one_window_opens_no_other_operation(string $key, string $window): void
    {
        $member = $this->member('none');

        [$method, $uri, $form] = $this->operation($key, $member);
        $this->{$method}($uri, $form + ['current_password' => 'password'])->assertSessionHasNoErrors();

        foreach (array_keys(self::operations()) as $other) {
            if ($other === $key || in_array($other, ['mfa confirm', 'mfa disable', 'recovery codes', 'ai token revoke'], true)) {
                continue;
            }
            [$method, $uri, $form] = $this->operation($other, $member);
            $this->{$method}($uri, $form)->assertSessionHasErrors('current_password');
        }
    }
}
