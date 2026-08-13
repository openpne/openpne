<?php

namespace Tests\Feature\Support;

use App\Features\Auth\Actions\CompleteRegistration;
use App\Features\Auth\RegistrationTokenSource;
use App\Features\Block\Actions\BlockMember;
use App\Features\Friend\Actions\AcceptFriendRequest;
use App\Features\Friend\Actions\SendFriendRequest;
use App\Features\Group\Actions\JoinGroup;
use App\Features\Group\JoinPolicy;
use App\Models\Group;
use App\Models\Member;
use App\Models\RegistrationToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * These four tables default created_at to the database's clock (`useCurrent()`), and no connection
 * timezone is configured — SQLite's CURRENT_TIMESTAMP is UTC while MySQL's follows the server, so a
 * row the app did not stamp means one column holding instants from two different clocks. Freezing
 * time well in the past makes any database-generated value obvious: it would land at the real now.
 */
class SiteTimezoneTimestampTest extends TestCase
{
    use RefreshDatabase;

    private const FROZEN = '2020-01-02 03:04:05';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::FROZEN);
    }

    public function test_a_friend_request_is_stamped_by_the_application(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create()->all();

        (new SendFriendRequest)($alice, $bob);

        $this->assertStampedByApp('friend_requests');
    }

    public function test_accepting_a_request_stamps_both_mirror_rows(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create()->all();
        DB::table('friend_requests')->insert([
            'requester_id' => $alice->getKey(),
            'target_id' => $bob->getKey(),
            'created_at' => now(),
        ]);

        (new AcceptFriendRequest)($bob, $alice);

        $this->assertStampedByApp('friendships', 2);
    }

    public function test_a_crossing_request_auto_accepts_with_stamped_rows(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create()->all();

        (new SendFriendRequest)($alice, $bob);
        (new SendFriendRequest)($bob, $alice);

        $this->assertStampedByApp('friendships', 2);
    }

    public function test_blocking_is_stamped_by_the_application(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create()->all();

        (new BlockMember)($alice, $bob);

        $this->assertStampedByApp('member_blocks');
    }

    public function test_a_join_request_is_stamped_by_the_application(): void
    {
        $group = Group::factory()->create(['register_policy' => JoinPolicy::Approval]);

        (new JoinGroup)(Member::factory()->create(), $group);

        $this->assertStampedByApp('group_join_requests');
    }

    public function test_an_invite_registration_stamps_the_auto_friendship(): void
    {
        $inviter = Member::factory()->create();
        $token = RegistrationToken::create([
            'email' => 'invitee@example.com',
            'token' => hash('sha256', 'raw-token'),
            'source' => RegistrationTokenSource::MemberInvite,
            'inviter_id' => $inviter->getKey(),
            'created_at' => now(),
        ]);

        app(CompleteRegistration::class)($token, ['name' => 'Invitee', 'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery']);

        $this->assertStampedByApp('friendships', 2);
    }

    /** Every row's created_at is the frozen application clock, so none came from the database default. */
    private function assertStampedByApp(string $table, int $expectedRows = 1): void
    {
        $stamps = DB::table($table)->pluck('created_at');

        $this->assertCount($expectedRows, $stamps);
        foreach ($stamps as $stamp) {
            $this->assertSame(self::FROZEN, Carbon::parse($stamp)->format('Y-m-d H:i:s'));
        }
    }
}
