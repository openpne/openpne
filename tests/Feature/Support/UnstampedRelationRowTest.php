<?php

namespace Tests\Feature\Support;

use App\Models\Group;
use App\Models\Member;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The relation tables carry no database default for created_at, so a write path that forgets the
 * stamp fails at the insert instead of putting the database clock in the column.
 */
class UnstampedRelationRowTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string, string}> */
    public static function memberPairs(): array
    {
        return [
            'friendships' => ['friendships', 'member_id', 'friend_id'],
            'friend requests' => ['friend_requests', 'requester_id', 'target_id'],
            'member blocks' => ['member_blocks', 'blocker_id', 'blocked_id'],
        ];
    }

    #[DataProvider('memberPairs')]
    public function test_a_member_pair_without_created_at_is_refused(string $table, string $a, string $b): void
    {
        [$one, $other] = Member::factory()->count(2)->create()->all();

        $this->expectException(QueryException::class);
        DB::table($table)->insert([$a => $one->getKey(), $b => $other->getKey()]);
    }

    public function test_a_join_request_without_created_at_is_refused(): void
    {
        $group = Group::factory()->create();
        $member = Member::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('group_join_requests')->insert(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);
    }
}
