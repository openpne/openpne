<?php

namespace Tests\Feature\Auth;

use App\Features\AiAccount\Actions\CreateAiAccount;
use App\Features\Auth\Actions\CompleteRegistration;
use App\Features\Auth\Actions\IssueRegistrationToken;
use App\Features\Auth\MemberCapacity;
use App\Features\Auth\RegistrationTokenSource;
use App\Models\Member;
use App\Models\RegistrationToken;
use App\Support\SnsSettingKey;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\Concerns\OpensSecondConnection;
use Tests\TestCase;
use Throwable;

/**
 * A second connection races each seat-taking write from inside the first one's lock: what is
 * pinned is that it waits on the cap's sns_settings row and that the seats never exceed the cap.
 */
class MemberCapacityLockTest extends TestCase
{
    use MigratesUpgradeTargetsOnce, OpensSecondConnection;

    private const LOCK_WAIT = 1205;

    private const PASSWORD = 'sufficiently-long-pw';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Two connections need a server; sqlite :memory: has one.');
        }

        $this->openSecondConnection();
        Notification::fake();
        $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'open');
        $this->setSnsSetting(SnsSettingKey::CaptchaEnabled, false);
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->closeSecondConnection();
        }

        parent::tearDown();
    }

    /** Runs $write on the second connection and reports whether it waited on the cap row. */
    private function race(callable $write): string
    {
        try {
            $this->onSecondConnection($write);

            return 'wrote';
        } catch (Throwable $e) {
            // A nested transaction rethrows the wait as a DeadlockException wrapping the query.
            $query = $e;
            while ($query !== null && ! $query instanceof QueryException) {
                $query = $query->getPrevious();
            }
            if ($query === null || (int) ($query->errorInfo[1] ?? 0) !== self::LOCK_WAIT) {
                throw $e;
            }

            return str_contains($query->getSql(), 'sns_settings') ? 'waited' : 'waited elsewhere: '.$query->getSql();
        }
    }

    private function pending(string $email): RegistrationToken
    {
        $raw = Str::random(40);

        return RegistrationToken::create(['email' => $email, 'token' => hash('sha256', $raw), 'source' => RegistrationTokenSource::AdminInvite->value, 'created_at' => now()]);
    }

    public function test_two_issuances_for_the_last_seat_are_serialized(): void
    {
        $this->setSnsSetting(SnsSettingKey::MemberCapacity, 1);
        $outcome = null;

        app(MemberCapacity::class)->reserveToken(function () use (&$outcome): void {
            $outcome = $this->race(fn () => app(IssueRegistrationToken::class)('second@example.com'));
            $this->pending('first@example.com');
        });

        $this->assertSame('waited', $outcome);
        $this->assertDatabaseCount('registration_tokens', 1);
        $this->assertTrue(app(MemberCapacity::class)->isFull());
    }

    public function test_two_completions_for_the_last_seat_are_serialized(): void
    {
        $this->setSnsSetting(SnsSettingKey::MemberCapacity, 1);
        $first = $this->pending('first@example.com');
        $second = $this->pending('second@example.com');
        $outcome = null;

        app(MemberCapacity::class)->reserveMember(function () use (&$outcome, $first, $second): void {
            $outcome = $this->race(fn () => app(CompleteRegistration::class)($second, ['name' => 'Second', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD]));
            app(CompleteRegistration::class)($first, ['name' => 'First', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD]);
        });

        $this->assertSame('waited', $outcome);
        $this->assertSame(1, Member::query()->whereNull('owner_member_id')->count());
    }

    public function test_two_ai_creations_for_the_last_seat_are_serialized(): void
    {
        $this->setSnsSetting(SnsSettingKey::AiAccountsEnabled, true);
        $this->setSnsSetting(SnsSettingKey::AiAccountCapacity, 1);
        Member::factory()->create(['id' => 1]);
        $first = Member::factory()->create();
        $second = Member::factory()->create();
        $outcome = null;

        DB::transaction(function () use (&$outcome, $first, $second): void {
            DB::table('sns_settings')->where('key', SnsSettingKey::AiAccountCapacity->value)->lockForUpdate()->first();
            $outcome = $this->race(fn () => app(CreateAiAccount::class)($second, 'Second'));
            Member::factory()->aiAccount($first)->create(['name' => 'First']);
        });

        $this->assertSame('waited', $outcome);
        $this->assertSame(1, Member::query()->whereNotNull('owner_member_id')->count());
    }
}
