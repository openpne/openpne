<?php

declare(strict_types=1);

namespace Tests\Feature\GroupEvent;

use App\Features\Group\GroupRole;
use App\Jobs\BroadcastEventCommentPosted;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\Member;
use App\Notifications\GroupEvent\EventCommentBroadcastNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\SqliteBindLimit;
use Tests\TestCase;

class BroadcastEventCommentPostedTest extends TestCase
{
    use RefreshDatabase;
    use SqliteBindLimit;

    public function test_an_exclusion_past_999_members_still_reaches_the_rest(): void
    {
        Notification::fake();
        $group = Group::factory()->create();
        [$author, $commenter, $general] = Member::factory()->count(3)->create()->all();
        foreach ([$author, $commenter, $general] as $member) {
            $group->members()->create(['member_id' => $member->getKey(), 'role' => GroupRole::Member]);
        }
        $excluded = [...$this->manyMembers(1000, $group), (int) $author->getKey(), (int) $commenter->getKey()];
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        $comment = $event->comments()->create(['member_id' => $commenter->getKey(), 'number' => 1, 'body' => 'a comment']);
        $job = new BroadcastEventCommentPosted((int) $event->getKey(), (int) $comment->getKey(), (int) $commenter->getKey(), $excluded);

        $this->withinSqliteBindLimit(fn () => app()->call([$job, 'handle']));

        Notification::assertSentTimes(EventCommentBroadcastNotification::class, 1);
        Notification::assertSentTo($general, EventCommentBroadcastNotification::class);
    }
}
