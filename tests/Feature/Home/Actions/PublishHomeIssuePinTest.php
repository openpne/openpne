<?php

declare(strict_types=1);

namespace Tests\Feature\Home\Actions;

use App\Features\Home\Data\SourceRef;
use App\Features\Home\HomeIssueSection;
use App\Models\Diary;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\SnsSettingKey;

class PublishHomeIssuePinTest extends PublishHomeIssueTestCase
{
    public function test_a_pin_leads_and_the_algorithm_shifts_down(): void
    {
        $posts = [];
        $this->at($this->now()->subHours(3), function () use (&$posts): void {
            foreach (range(HomeIssueSection::Stories->cap(), 1) as $replies) {
                $posts[$replies] = $this->postWithReplies($replies);
            }
        });

        // Older than the window: a pin overrides the window as well as the ranking.
        $pinned = $this->at($this->now()->subDays(30), fn (): Diary => Diary::factory()->create());

        $issue = $this->publish(pin: SourceRef::of($pinned));

        $this->assertNotNull($issue);
        $refs = $this->refs($issue, HomeIssueSection::Stories);
        $this->assertCount(HomeIssueSection::Stories->cap(), $refs);
        $this->assertSame($this->ref($pinned), $refs[0]);
        $this->assertSame($this->ref($posts[HomeIssueSection::Stories->cap()]), $refs[1]);
        $this->assertNotContains($this->ref($posts[1]), $refs, 'the cap did not shift the last story out');
    }

    public function test_a_pin_the_algorithm_also_chose_is_not_featured_twice(): void
    {
        $post = $this->at($this->now()->subHour(), fn (): TimelinePost => $this->postWithReplies(3));
        $other = $this->at($this->now()->subHour(), fn (): TimelinePost => $this->postWithReplies(9));

        $issue = $this->publish(pin: SourceRef::of($post));

        $this->assertNotNull($issue);
        $this->assertSame([$this->ref($post), $this->ref($other)], $this->refs($issue, HomeIssueSection::Stories));
    }

    public function test_a_pin_no_member_may_read_is_ignored(): void
    {
        $private = $this->at($this->now()->subDays(30), fn (): TimelinePost => TimelinePost::factory()->private()->create());
        $carrier = $this->at($this->now()->subHour(), fn (): TimelinePost => TimelinePost::factory()->create());

        $plan = $this->action()->plan($this->now(), SourceRef::of($private));

        $this->assertNotNull($plan);
        $this->assertSame($this->ref($private), $plan->ignoredPin?->key());
        $this->assertSame([$this->ref($carrier)], $this->planned($plan, HomeIssueSection::Stories));
    }

    public function test_a_pin_that_is_not_a_story_is_ignored(): void
    {
        $member = $this->at($this->now()->subHour(), fn (): Member => Member::factory()->create());

        $plan = $this->action()->plan($this->now(), new SourceRef('member', (int) $member->id));

        $this->assertNotNull($plan);
        $this->assertSame($this->ref($member), $plan->ignoredPin?->key());
        $this->assertSame([], $this->planned($plan, HomeIssueSection::Stories));
        $this->assertContains($this->ref($member), $this->planned($plan, HomeIssueSection::Newcomers));
    }

    public function test_a_pin_whose_unit_is_off_is_ignored(): void
    {
        $diary = $this->at($this->now()->subHour(), fn (): Diary => Diary::factory()->create());
        $this->at($this->now()->subHour(), fn (): TimelinePost => TimelinePost::factory()->create());

        $this->setSnsSetting(SnsSettingKey::FeatureDiaryEnabled, false);

        $plan = $this->action()->plan($this->now(), SourceRef::of($diary));

        $this->assertNotNull($plan);
        $this->assertSame($this->ref($diary), $plan->ignoredPin?->key());
    }
}
