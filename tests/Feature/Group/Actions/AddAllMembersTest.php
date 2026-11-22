<?php

declare(strict_types=1);

namespace Tests\Feature\Group\Actions;

use App\Features\Group\Actions\AddAllMembers;
use App\Features\Group\GroupRole;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\SqliteBindLimit;
use Tests\TestCase;

class AddAllMembersTest extends TestCase
{
    use RefreshDatabase;
    use SqliteBindLimit;

    public function test_adds_non_members_as_plain_members_and_returns_count(): void
    {
        $group = Group::factory()->create();
        $a = Member::factory()->create();
        $b = Member::factory()->create();

        $added = app(AddAllMembers::class)($group);

        $this->assertSame(2, $added);
        $this->assertDatabaseHas('group_members', [
            'group_id' => $group->getKey(),
            'member_id' => $a->getKey(),
            'role' => GroupRole::Member->value,
        ]);
        $this->assertDatabaseHas('group_members', [
            'group_id' => $group->getKey(),
            'member_id' => $b->getKey(),
        ]);
    }

    public function test_is_idempotent_and_preserves_existing_roles(): void
    {
        $group = Group::factory()->create();
        $admin = Member::factory()->create();
        GroupMember::factory()->admin()->create([
            'group_id' => $group->getKey(),
            'member_id' => $admin->getKey(),
        ]);
        Member::factory()->create(); // one outsider

        $first = app(AddAllMembers::class)($group);
        $second = app(AddAllMembers::class)($group);

        $this->assertSame(1, $first);  // only the outsider was added
        $this->assertSame(0, $second); // re-run adds nothing
        // The existing admin keeps the Admin role (their row was not overwritten).
        $this->assertDatabaseHas('group_members', [
            'group_id' => $group->getKey(),
            'member_id' => $admin->getKey(),
            'role' => GroupRole::Admin->value,
        ]);
    }

    public function test_clears_pending_join_requests_for_the_community(): void
    {
        $group = Group::factory()->create();
        $applicant = Member::factory()->create();
        DB::table('group_join_requests')->insert([
            'group_id' => $group->getKey(),
            'member_id' => $applicant->getKey(),
            'created_at' => now(),
        ]);

        app(AddAllMembers::class)($group);

        $this->assertDatabaseMissing('group_join_requests', [
            'group_id' => $group->getKey(),
            'member_id' => $applicant->getKey(),
        ]);
        $this->assertDatabaseHas('group_members', [
            'group_id' => $group->getKey(),
            'member_id' => $applicant->getKey(),
        ]);
    }

    public function test_adds_more_members_than_one_insert_could_bind(): void
    {
        $group = Group::factory()->create();
        $ids = $this->manyMembers(150);

        $this->assertSame(150, $this->withinSqliteBindLimit(fn (): int => app(AddAllMembers::class)($group)));
        $this->assertSame($ids, GroupMember::query()->where('group_id', $group->getKey())->orderBy('member_id')->pluck('member_id')->map(fn (mixed $id): int => (int) $id)->all());
    }

    public function test_a_failure_inside_a_page_adds_none_of_its_rows(): void
    {
        $group = Group::factory()->create();
        $this->manyMembers(150);
        $inserts = 0;
        DB::listen(function (QueryExecuted $query) use (&$inserts): void {
            if (str_contains($query->sql, 'group_members') && str_starts_with($query->sql, 'insert') && ++$inserts === 2) {
                throw new RuntimeException('second insert');
            }
        });

        try {
            app(AddAllMembers::class)($group);
            $this->fail('the second insert should have thrown');
        } catch (RuntimeException) {
        }

        $this->assertSame(2, $inserts);
        $this->assertSame(0, GroupMember::query()->where('group_id', $group->getKey())->count());
    }
}
