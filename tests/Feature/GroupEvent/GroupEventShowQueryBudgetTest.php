<?php

namespace Tests\Feature\GroupEvent;

use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GroupEventShowQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{string}> */
    public static function surfaces(): iterable
    {
        yield 'classic' => ['classic_default'];
        yield 'modern' => ['modern_default'];
    }

    /**
     * Each comment has an author of its own, so a per-comment lookup would scale with the page. The
     * comments are already examined for link cards: the first view's per-row marking is not a read.
     */
    #[DataProvider('surfaces')]
    public function test_a_page_of_comments_costs_no_query_per_comment(string $surface): void
    {
        config(['openpne.surface_mode' => $surface]);
        $group = Group::factory()->create();
        $viewer = Member::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $viewer->getKey()]);

        $short = $this->eventWithComments($group, 2);
        $long = $this->eventWithComments($group, 20);

        $this->actingAs($viewer);
        $forTwo = $this->applicationQueryCounts(fn () => $this->get(route('group.events.show', $short))->assertOk());
        $forTwenty = $this->applicationQueryCounts(fn () => $this->get(route('group.events.show', $long))->assertOk());

        $grew = array_filter($forTwenty, fn (int $count, string $sql): bool => $count > ($forTwo[$sql] ?? 0), ARRAY_FILTER_USE_BOTH);
        $this->assertSame([], $grew, 'queries that ran more often for 20 comments than for 2');
    }

    private function queryCounts(callable $run): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $run();
            $counts = [];
            foreach (DB::getQueryLog() as $query) {
                if (preg_match('/["`]cache(_locks)?["`]/', $query['query']) !== 1) {
                    // Eager loads inline their key lists, so the list is folded before statements are compared.
                    $sql = preg_replace('/ in \([^)]*\)/', ' in (...)', $query['query']);
                    $counts[$sql] = ($counts[$sql] ?? 0) + 1;
                }
            }

            return $counts;
        } finally {
            DB::disableQueryLog();
        }
    }

    private function eventWithComments(Group $group, int $count): GroupEvent
    {
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey()]);
        foreach (range(1, $count) as $number) {
            GroupEventComment::factory()->create(['group_event_id' => $event->getKey(), 'number' => $number, 'link_card_synced_at' => now()]);
        }

        return $event;
    }
}
