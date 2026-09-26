<?php

namespace Tests\Feature\Diary\Queries;

use App\Features\Diary\Queries\DiaryCommentNotificationRecipients;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\Member;
use App\Notifications\CommentReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ShapesAudience;
use Tests\TestCase;

class DiaryCommentNotificationRecipientsTest extends TestCase
{
    use RefreshDatabase;
    use ShapesAudience;

    private int $number = 0;

    private function comment(Diary $diary, Member $by): void
    {
        DiaryComment::factory()->create(['diary_id' => $diary->getKey(), 'member_id' => $by->getKey(), 'number' => ++$this->number]);
    }

    public function test_the_owner_is_told_of_a_reply_and_the_other_commenters_of_a_related_one_once_each(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);
        $commenter = Member::factory()->create();
        $other = Member::factory()->create();
        $this->comment($diary, $other);
        $this->comment($diary, $other);
        $this->comment($diary, $owner);
        $this->comment($diary, $commenter);

        $recipients = app(DiaryCommentNotificationRecipients::class)($diary, $commenter);

        $this->assertSame(
            [[$owner->getKey(), CommentReason::Reply], [$other->getKey(), CommentReason::Related]],
            $this->reasonsOf($recipients),
        );
    }

    public function test_the_owner_commenting_on_their_own_diary_tells_only_the_others(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);
        $other = Member::factory()->create();
        $this->comment($diary, $other);

        $recipients = app(DiaryCommentNotificationRecipients::class)($diary, $owner);

        $this->assertSame([[$other->getKey(), CommentReason::Related]], $this->reasonsOf($recipients));
    }

    public function test_each_recipient_is_judged_as_of_now(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->friends()->create(['member_id' => $owner->getKey()]);
        $commenter = Member::factory()->create();
        $this->befriend($commenter, $owner);
        $friend = Member::factory()->create();
        $this->befriend($friend, $owner);
        $this->comment($diary, $friend);
        $unfriended = Member::factory()->create();
        $this->comment($diary, $unfriended);
        $banned = $this->banned();
        $this->befriend($banned, $owner);
        $this->comment($diary, $banned);
        $blocking = Member::factory()->create();
        $this->befriend($blocking, $owner);
        $this->block($blocking, $commenter);
        $this->comment($diary, $blocking);
        $this->block($owner, $commenter);

        $recipients = app(DiaryCommentNotificationRecipients::class)($diary, $commenter);

        $this->assertSame([[$friend->getKey(), CommentReason::Related]], $this->reasonsOf($recipients));
    }
}
