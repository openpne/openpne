<?php

declare(strict_types=1);

namespace Tests\Feature\Home\Queries;

use App\Features\GroupTopic\TopicReadAccess;
use App\Features\Home\Data\HomeIssueWindow;
use App\Features\Home\Data\PlannedItem;
use App\Features\Home\Queries\TalkBurstCandidates;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TalkBurstCandidatesTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-08-27 06:00:00';

    private function window(): HomeIssueWindow
    {
        $end = CarbonImmutable::parse(self::NOW);

        return new HomeIssueWindow($end->subDay(), $end);
    }

    private function said(Group $group, ?Member $by, CarbonImmutable $at): GroupMessage
    {
        Carbon::setTestNow($at);

        try {
            return GroupMessage::factory()->create(['group_id' => $group->getKey(), 'member_id' => $by?->getKey()]);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function reacted(GroupMessage $message, Member $by): void
    {
        DB::table('reactions')->insert([
            'reactable_type' => $message->getMorphClass(),
            'reactable_id' => $message->getKey(),
            'member_id' => $by->getKey(),
            'emoji' => "\u{1F44D}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  Collection<int, PlannedItem>  $items
     * @return list<int>
     */
    private function ids($items): array
    {
        return $items->map(fn (PlannedItem $item): int => $item->sourceId)->values()->all();
    }

    public function test_a_burst_is_scored_by_what_was_said_inside_the_window_and_dated_by_its_last_word(): void
    {
        $window = $this->window();
        $group = Group::factory()->create();
        $a = Member::factory()->create();
        $b = Member::factory()->create();
        $this->said($group, $a, $window->start);
        $first = $this->said($group, $a, $window->start->addSecond());
        $this->said($group, $a, $window->end->subHours(2));
        $last = $this->said($group, $b, $window->end->subHour());
        $this->said($group, $b, $window->end->addSecond());
        $this->reacted($first, $b);
        $this->reacted($last, $a);
        $this->reacted($this->said($group, $b, $window->end->addMinute()), $a);

        $items = app(TalkBurstCandidates::class)($window, 10);

        $this->assertSame([$group->getKey()], $this->ids($items));
        $burst = $items->first();
        $this->assertSame(3 + 2 + 2, $burst?->score);
        $this->assertSame([
            'messages' => 3,
            'authors' => 2,
            'reactions' => 2,
            'since' => $window->start->toIso8601String(),
            'until' => $window->end->toIso8601String(),
        ], $burst?->stats);
        $this->assertTrue($window->end->subHour()->equalTo($burst?->createdAt));
    }

    public function test_a_withdrawn_author_is_nobody_and_a_members_only_talk_is_no_story(): void
    {
        $window = $this->window();
        $open = Group::factory()->create();
        $this->said($open, null, $window->end);
        $this->said($open, null, $window->end);
        $walled = Group::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        $this->said($walled, Member::factory()->create(), $window->end);

        $items = app(TalkBurstCandidates::class)($window, 10);

        $this->assertSame([$open->getKey()], $this->ids($items));
        $this->assertSame(['messages' => 2, 'authors' => 0], array_intersect_key($items->first()?->stats ?? [], ['messages' => 0, 'authors' => 0]));
    }

    public function test_ranked_by_the_whole_score_then_by_the_last_word_and_cut_to_the_limit(): void
    {
        $window = $this->window();
        $member = Member::factory()->create();
        $quietEarly = Group::factory()->create();
        $this->said($quietEarly, $member, $window->end->subHours(5));
        $quietLate = Group::factory()->create();
        $this->said($quietLate, $member, $window->end->subHour());
        $wordy = Group::factory()->create();
        $this->said($wordy, $member, $window->end->subHours(8));
        $this->said($wordy, $member, $window->end->subHours(7));
        $liked = Group::factory()->create();
        $once = $this->said($liked, $member, $window->end->subHours(9));
        foreach (Member::factory()->count(3)->create() as $reader) {
            $this->reacted($once, $reader);
        }

        $items = app(TalkBurstCandidates::class)($window, 3);

        $this->assertSame([$liked->getKey(), $wordy->getKey(), $quietLate->getKey()], $this->ids($items));
    }
}
