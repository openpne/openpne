<?php

declare(strict_types=1);

namespace Tests\Feature\Community;

use App\Features\Community\CommunityRole;
use App\Features\CommunityTopic\Actions\CreateTopicComment;
use App\Jobs\BroadcastTopicCommentPosted;
use App\Models\Community;
use App\Models\CommunityTopic;
use App\Models\CommunityTopicComment;
use App\Models\Member;
use App\Notifications\CommentReason;
use App\Notifications\CommunityTopic\TopicCommentBroadcastNotification;
use App\Notifications\CommunityTopic\TopicCommentedNotification;
use App\Notifications\Settings\NotificationChannel;
use App\Notifications\Settings\NotificationKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The community-wide side of a topic comment (CommentNewPost). Precedence Reply > Related > Community:
 * the broadcast reaches members who are neither the author nor a co-commenter, so no member is
 * notified twice. The author/co-commenter half lives in NotifyTopicCommentPosted (Reply/Related).
 */
class BroadcastCommunityCommentTest extends TestCase
{
    use RefreshDatabase;

    private function member(Community $community, ?Member $member = null): Member
    {
        $member ??= Member::factory()->create();
        $community->members()->create(['member_id' => $member->getKey(), 'role' => CommunityRole::Member]);

        return $member;
    }

    private function comment(CommunityTopic $topic, Member $author): CommunityTopicComment
    {
        return $topic->comments()->create([
            'member_id' => $author->getKey(),
            'number' => (int) $topic->comments()->max('number') + 1,
            'body' => 'a comment',
        ]);
    }

    /** The author + co-commenter ids the listener snapshots at dispatch time. @return list<int> */
    private function excluded(CommunityTopic $topic): array
    {
        $ids = $topic->comments()->whereNotNull('member_id')->distinct()->pluck('member_id')->all();
        if ($topic->member_id !== null) {
            $ids[] = (int) $topic->member_id;
        }

        return array_map('intval', $ids);
    }

    private function broadcast(CommunityTopic $topic, CommunityTopicComment $comment, Member $commenter, array $excluded): void
    {
        app()->call([new BroadcastTopicCommentPosted((int) $topic->getKey(), (int) $comment->getKey(), (int) $commenter->getKey(), $excluded), 'handle']);
    }

    public function test_broadcasts_to_members_but_not_the_author_co_commenters_or_commenter(): void
    {
        Notification::fake();
        $community = Community::factory()->create();
        $author = $this->member($community);
        $coCommenter = $this->member($community);
        $general = $this->member($community);
        $commenter = $this->member($community);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);
        $this->comment($topic, $coCommenter);            // prior comment → a co-commenter
        $comment = $this->comment($topic, $commenter);   // the new comment

        $this->broadcast($topic, $comment, $commenter, $this->excluded($topic));

        Notification::assertSentTo(
            $general,
            TopicCommentBroadcastNotification::class,
            fn (TopicCommentBroadcastNotification $n, array $channels) => $channels === ['mail', 'database'],
        );
        // Author, co-commenter, and commenter get Reply / Related / nothing — never the broadcast.
        foreach ([$author, $coCommenter, $commenter] as $excluded) {
            Notification::assertNotSentTo($excluded, TopicCommentBroadcastNotification::class);
        }
    }

    public function test_opting_out_of_the_comment_kind_drops_the_channel(): void
    {
        Notification::fake();
        $community = Community::factory()->create();
        $author = $this->member($community);
        $commenter = $this->member($community);
        $general = $this->member($community);
        $general->setNotificationSetting(NotificationKind::CommunityTopicCommentNewPost, NotificationChannel::Mail, false);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);
        $comment = $this->comment($topic, $commenter);

        $this->broadcast($topic, $comment, $commenter, $this->excluded($topic));

        Notification::assertSentTo(
            $general,
            TopicCommentBroadcastNotification::class,
            fn (TopicCommentBroadcastNotification $n, array $channels) => $channels === ['database'],
        );
    }

    public function test_a_blocked_member_is_excluded_from_the_broadcast(): void
    {
        Notification::fake();
        $community = Community::factory()->create();
        $author = $this->member($community);
        $commenter = $this->member($community);
        $blocked = $this->member($community);
        DB::table('member_blocks')->insert(['blocker_id' => $blocked->getKey(), 'blocked_id' => $commenter->getKey()]);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);
        $comment = $this->comment($topic, $commenter);

        $this->broadcast($topic, $comment, $commenter, $this->excluded($topic));

        Notification::assertNotSentTo($blocked, TopicCommentBroadcastNotification::class);
    }

    public function test_a_co_commenters_deleted_comment_still_keeps_them_out_of_the_broadcast(): void
    {
        // The exclusion is snapshotted at dispatch, so a co-commenter who already got Related is not
        // re-included (and double-notified) if their comment is deleted before the async job runs.
        Notification::fake();
        $community = Community::factory()->create();
        $author = $this->member($community);
        $coCommenter = $this->member($community);
        $commenter = $this->member($community);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);
        $priorComment = $this->comment($topic, $coCommenter);
        $comment = $this->comment($topic, $commenter);
        $excluded = $this->excluded($topic); // captured while both comments exist

        $priorComment->delete(); // the co-commenter's comment is gone by the time the job runs

        $this->broadcast($topic, $comment, $commenter, $excluded);

        Notification::assertNotSentTo($coCommenter, TopicCommentBroadcastNotification::class);
    }

    public function test_the_comment_listener_dispatches_the_broadcast_and_notifies_the_author_inline(): void
    {
        Bus::fake([BroadcastTopicCommentPosted::class]);
        Notification::fake();
        $community = Community::factory()->create();
        $author = $this->member($community);
        $commenter = $this->member($community);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);

        app(CreateTopicComment::class)($commenter, $topic, 'hello');

        // Inline: the author gets a Reply. Off the request: the community-wide broadcast job, carrying the
        // author + commenter in its snapshotted exclusion.
        Notification::assertSentTo(
            $author,
            TopicCommentedNotification::class,
            fn (TopicCommentedNotification $n) => $n->reason === CommentReason::Reply,
        );
        Bus::assertDispatched(
            BroadcastTopicCommentPosted::class,
            fn (BroadcastTopicCommentPosted $job) => $job->topicId === (int) $topic->getKey()
                && $job->commenterId === (int) $commenter->getKey()
                && in_array($author->getKey(), $job->excludedMemberIds, true)
                && in_array($commenter->getKey(), $job->excludedMemberIds, true),
        );
    }
}
