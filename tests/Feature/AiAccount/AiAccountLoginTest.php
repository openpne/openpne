<?php

declare(strict_types=1);

namespace Tests\Feature\AiAccount;

use App\Actions\Fortify\AuthenticateMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The third refusal on an AI account's behalf. The first two are structural — no email to look up,
 * no password to verify — and the members constraint keeps them true; this pins the one that still
 * refuses when they are not.
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

    public function test_an_ai_account_is_refused_even_holding_a_valid_password(): void
    {
        // The DB belt refuses the write below, which is the point: the row this guard exists for is
        // only reachable with the belt lifted. Lifting it is DDL — transactional on SQLite, where
        // the test's own transaction rolls it back, but an implicit COMMIT on MySQL that would leave
        // the constraint off for the rest of that worker's tests. The guard is plain PHP, so the
        // SQLite lane covers it for both.
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('lifting the members constraint is non-transactional DDL outside SQLite');
        }

        DB::unprepared(sprintf(
            'DROP TRIGGER IF EXISTS %1$s_insert; DROP TRIGGER IF EXISTS %1$s_update;',
            self::TRIGGERS
        ));

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
}
