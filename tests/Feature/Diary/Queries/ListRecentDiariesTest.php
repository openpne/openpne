<?php

namespace Tests\Feature\Diary\Queries;

use App\Features\Diary\Queries\ListRecentDiaries;
use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ListRecentDiariesTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_members_and_open_diaries_from_every_member(): void
    {
        $viewer = Member::factory()->create();
        [$alice, $bob] = Member::factory()->count(2)->create()->all();
        $this->createDiaryFor($alice, Visibility::Members);
        $this->createDiaryFor($bob, Visibility::Open);
        $this->createDiaryFor($viewer, Visibility::Members);

        $result = (new ListRecentDiaries)($viewer);

        $this->assertSame(3, $result->total());
    }

    public function test_hides_friends_and_private_visibility(): void
    {
        $viewer = Member::factory()->create();
        $owner = Member::factory()->create();
        $this->createDiaryFor($owner, Visibility::Friends);
        $this->createDiaryFor($owner, Visibility::Private);
        $this->createDiaryFor($owner, Visibility::Members);

        $result = (new ListRecentDiaries)($viewer);

        // The all-members tier excludes Friends/Private even for a friend; those belong
        // to the friend feed and the owner's own archive.
        $this->assertSame(1, $result->total());
    }

    public function test_excludes_diaries_whose_owner_blocks_the_viewer(): void
    {
        $viewer = Member::factory()->create();
        $owner = Member::factory()->create();
        $this->createDiaryFor($owner, Visibility::Members);
        DB::table('member_blocks')->insert([
            'blocker_id' => $owner->getKey(),
            'blocked_id' => $viewer->getKey(),
        ]);

        $this->assertSame(0, (new ListRecentDiaries)($viewer)->total());
    }

    public function test_orders_by_created_at_descending(): void
    {
        $viewer = Member::factory()->create();
        $first = $this->createDiaryFor($viewer, Visibility::Members, createdAt: '2026-01-01');
        $second = $this->createDiaryFor($viewer, Visibility::Members, createdAt: '2026-03-01');

        $result = (new ListRecentDiaries)($viewer);

        $this->assertSame($second->getKey(), $result->items()[0]->getKey());
        $this->assertSame($first->getKey(), $result->items()[1]->getKey());
    }

    public function test_paginates(): void
    {
        $viewer = Member::factory()->create();
        Diary::factory()->count(25)->create(['visibility' => Visibility::Members]);

        $result = (new ListRecentDiaries)($viewer, perPage: 20);

        $this->assertSame(20, $result->perPage());
        $this->assertSame(25, $result->total());
    }

    private function createDiaryFor(Member $member, Visibility $visibility, ?string $createdAt = null): Diary
    {
        $attrs = ['member_id' => $member->getKey(), 'visibility' => $visibility];
        if ($createdAt !== null) {
            $attrs['created_at'] = $createdAt;
        }

        return Diary::factory()->create($attrs);
    }
}
