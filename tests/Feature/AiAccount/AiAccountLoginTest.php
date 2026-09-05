<?php

declare(strict_types=1);

namespace Tests\Feature\AiAccount;

use App\Actions\Fortify\AuthenticateMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Three doors into a member row: a password attempt, a session carrying its id, and a remember-me
 * cookie. The members constraint shuts only the first and the third, so these pin the refusals that
 * still stand with it lifted and the session door, which needs no credential at all.
 */
class AiAccountLoginTest extends TestCase
{
    use RefreshDatabase;

    private const TRIGGERS = 'chk_members_ai_account_has_no_credentials';

    private function attempt(string $email, string $password): ?Member
    {
        return app(AuthenticateMember::class)(Request::create('/login', 'POST', [
            'email' => $email,
            'password' => $password,
        ]));
    }

    /**
     * The row a guard exists for is only reachable with the belt lifted. Lifting it is DDL —
     * transactional on SQLite, but an implicit COMMIT on MySQL that would leave the constraint off
     * for the rest of that worker's tests — so this runs on SQLite alone.
     */
    private function liftTheCredentialConstraint(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('lifting the members constraint is non-transactional DDL outside SQLite');
        }

        DB::unprepared(sprintf(
            'DROP TRIGGER IF EXISTS %1$s_insert; DROP TRIGGER IF EXISTS %1$s_update;',
            self::TRIGGERS
        ));
    }

    private function sessionKey(): string
    {
        return Auth::guard('member')->getName();
    }

    /** A remember-me cookie as the guard writes it: `id|token|password hash`, under its own name. */
    private function withRecaller(Member $member, string $token): static
    {
        return $this->withCookie(
            Auth::guard('member')->getRecallerName(),
            $member->getKey().'|'.$token.'|'.(string) $member->getAuthPassword(),
        );
    }

    public function test_an_ai_account_is_refused_even_holding_a_valid_password(): void
    {
        $this->liftTheCredentialConstraint();

        $aiAccount = Member::factory()->aiAccount()->create();
        $aiAccount->forceFill([
            'email' => 'helper@example.test',
            'password' => Hash::make('a-fresh-strong-password'),
        ])->save();

        $this->assertNull($this->attempt('helper@example.test', 'a-fresh-strong-password'));

        // And through the real form, where the same row is a live account with a live password.
        $this->post('/login', ['email' => 'helper@example.test', 'password' => 'a-fresh-strong-password']);
        $this->assertGuest('member');
    }

    public function test_an_ordinary_member_with_the_same_password_authenticates(): void
    {
        // The control: the refusal above is the owner link, not a broken password or a broken lookup.
        $member = Member::factory()->create(['email' => 'human@example.test']);
        $member->forceFill(['password' => Hash::make('a-fresh-strong-password')])->save();

        $this->assertTrue($this->attempt('human@example.test', 'a-fresh-strong-password')?->is($member));
    }

    public function test_a_session_carrying_an_ai_accounts_id_signs_nobody_in(): void
    {
        // The door the form's refusal does not cover: session restore asks the provider for an id
        // and never looks at a credential.
        $aiAccount = Member::factory()->aiAccount()->create();

        $this->withSession([$this->sessionKey() => $aiAccount->getKey()])
            ->get('/')
            ->assertRedirect('/login');

        $this->assertGuest('member');
    }

    public function test_a_session_carrying_an_ordinary_members_id_signs_them_in(): void
    {
        // The control: the refusal above is the owner link, not a session key this test got wrong.
        $member = Member::factory()->create();

        $this->withSession([$this->sessionKey() => $member->getKey()])
            ->get('/')
            ->assertOk();

        $this->assertAuthenticatedAs($member, 'member');
    }

    public function test_a_remember_me_cookie_for_an_ai_account_restores_nobody(): void
    {
        // The other credential-free door: a recaller is matched against `members.remember_token`
        // alone, and this pins the refusal for a row that somehow holds one.
        $this->liftTheCredentialConstraint();

        $aiAccount = Member::factory()->aiAccount()->create();
        $token = Str::random(60);
        $aiAccount->forceFill(['remember_token' => $token])->save();

        $this->withRecaller($aiAccount, $token)
            ->get('/')
            ->assertRedirect('/login');

        $this->assertGuest('member');
    }

    public function test_a_remember_me_cookie_for_an_ordinary_member_restores_them(): void
    {
        // The control: the cookie above is well-formed and under the right name — this one restores.
        $member = Member::factory()->create();
        $token = Str::random(60);
        $member->forceFill(['remember_token' => $token])->save();

        $this->withRecaller($member, $token)
            ->get('/')
            ->assertOk();

        $this->assertAuthenticatedAs($member, 'member');
    }

    public function test_a_credential_lookup_never_produces_an_ai_account(): void
    {
        // The provider's third retrieval, the one the password broker uses: an AI account has no
        // address to reset, and must not acquire one by acquiring an email.
        $this->liftTheCredentialConstraint();

        $aiAccount = Member::factory()->aiAccount()->create();
        $aiAccount->forceFill(['email' => 'helper@example.test'])->save();

        $provider = Auth::createUserProvider('members');

        $this->assertNull($provider->retrieveByCredentials(['email' => 'helper@example.test']));
        $this->assertNull($provider->retrieveById($aiAccount->getKey()));
    }
}
