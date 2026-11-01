<?php

declare(strict_types=1);

namespace Tests\Feature\AiAccount;

use App\Actions\Fortify\ResetMemberPassword;
use App\Features\AiAccount\Actions\RevokeMcpTokens;
use App\Features\Member\Actions\RejectMemberLogin;
use App\Features\Member\Actions\WithdrawMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\TestCase;

/** Every path that ends a member's personal tokens ends their OAuth rows the same way. */
class OAuthTokenSweepTest extends TestCase
{
    use RefreshDatabase;

    private function grant(Member $member): void
    {
        $client = Client::factory()->asPublic()->create();
        $access = Passport::token()->forceFill([
            'id' => Str::random(80), 'user_id' => $member->getKey(), 'client_id' => $client->getKey(),
            'scopes' => ['mcp:use'], 'revoked' => false, 'expires_at' => now()->addHour(),
        ]);
        $access->save();
        Passport::refreshToken()->forceFill([
            'id' => Str::random(80), 'access_token_id' => $access->getKey(), 'revoked' => false, 'expires_at' => now()->addDay(),
        ])->save();
        Passport::authCode()->forceFill([
            'id' => Str::random(80), 'user_id' => $member->getKey(), 'client_id' => $client->getKey(),
            'scopes' => json_encode(['mcp:use']), 'revoked' => false, 'expires_at' => now()->addMinutes(10),
        ])->save();
    }

    private function rows(Member $member): int
    {
        return Passport::token()->newQuery()->where('user_id', $member->getKey())->count()
            + Passport::authCode()->newQuery()->where('user_id', $member->getKey())->count()
            + Passport::refreshToken()->newQuery()->count();
    }

    public function test_a_ban_ends_the_members_and_their_accounts_grants(): void
    {
        Member::factory()->create();
        $owner = Member::factory()->create();
        $aiAccount = Member::factory()->aiAccount($owner)->create();
        $this->grant($owner);
        $this->grant($aiAccount);

        app(RejectMemberLogin::class)($owner);

        $this->assertSame(0, $this->rows($owner) + $this->rows($aiAccount));
    }

    public function test_a_withdrawal_ends_the_grants(): void
    {
        Member::factory()->create();
        $owner = Member::factory()->create();
        $this->grant($owner);

        app(WithdrawMember::class)($owner);

        $this->assertSame(0, $this->rows($owner));
    }

    public function test_a_password_reset_ends_the_members_own_grants_and_keeps_their_personal_tokens(): void
    {
        Notification::fake();
        $owner = Member::factory()->create();
        $this->grant($owner);
        $owner->createToken('mcp', ['mcp:read']);

        app(ResetMemberPassword::class)->reset($owner, [
            'password' => 'a-fresh-strong-password',
            'password_confirmation' => 'a-fresh-strong-password',
        ]);

        $this->assertSame(0, $this->rows($owner));
        $this->assertSame(1, $owner->tokens()->count());
    }

    public function test_a_password_change_in_session_ends_the_members_own_grants(): void
    {
        Notification::fake();
        $owner = Member::factory()->create();
        $this->grant($owner);

        $this->actingAs($owner)->post('/member/config/password', [
            'current_password' => 'password',
            'password' => 'a-fresh-strong-password',
            'password_confirmation' => 'a-fresh-strong-password',
        ])->assertRedirect();

        $this->assertSame(0, $this->rows($owner));
    }

    public function test_the_operator_revoke_reaches_the_grants_too(): void
    {
        $member = Member::factory()->create();
        $this->grant($member);
        $member->createToken('mcp', ['mcp:read']);

        $this->assertSame(2, app(RevokeMcpTokens::class)($member));
        $this->assertSame(0, $this->rows($member));
    }
}
