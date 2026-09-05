<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Features\Notifications\NotificationTarget;
use App\Models\Diary;
use App\Models\DirectMessage;
use App\Models\DirectMessageRecipient;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupMember;
use App\Models\GroupMessage;
use App\Models\GroupTopic;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Notifications\Diary\DiaryCommentedNotification;
use App\Notifications\Diary\DiaryPostedNotification;
use App\Notifications\DirectMessage\DirectMessageReceivedNotification;
use App\Notifications\FeatureNotificationMap;
use App\Notifications\Friend\FriendRequestAcceptedNotification;
use App\Notifications\Friend\FriendRequestedNotification;
use App\Notifications\Group\AdminTransferRequestedNotification;
use App\Notifications\Group\GroupJoinedNotification;
use App\Notifications\Group\SubAdminAppointedNotification;
use App\Notifications\GroupEvent\EventCommentBroadcastNotification;
use App\Notifications\GroupEvent\EventCommentedNotification;
use App\Notifications\GroupEvent\EventPostedNotification;
use App\Notifications\GroupTalk\GroupTalkMentionedNotification;
use App\Notifications\GroupTalk\GroupTalkMessagePostedNotification;
use App\Notifications\GroupTopic\TopicCommentBroadcastNotification;
use App\Notifications\GroupTopic\TopicCommentedNotification;
use App\Notifications\GroupTopic\TopicPostedNotification;
use App\Notifications\Timeline\TimelineMentionedNotification;
use App\Notifications\Timeline\TimelinePostedNotification;
use App\Notifications\Timeline\TimelineRepliedNotification;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The table walks FeatureNotificationMap::CLASSES, which covers every database notification only over the
 * feature namespaces FeatureNotificationCoverageTest::OWNED walks (`Auth` / `Member` are mail only, `Push`
 * is WebPush). A database notification outside those has to be added to both.
 */
class NotificationTargetReadTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: class-string}> */
    public static function notificationClasses(): array
    {
        $cases = [];
        foreach (FeatureNotificationMap::CLASSES as $class) {
            $cases[class_basename($class)] = [$class];
        }

        return $cases;
    }

    #[DataProvider('notificationClasses')]
    public function test_reading_the_target_spends_the_row(string $class): void
    {
        $viewer = Member::factory()->create();
        $bystander = Member::factory()->create();
        $scenario = $this->scenario($class, $viewer);

        $row = $this->seedRow($viewer, $class, $scenario['data']);
        $theirs = $this->seedRow($bystander, $class, $scenario['data']);
        $decoys = array_map(fn (array $decoy): DatabaseNotification => $this->seedRow($viewer, $decoy[0], $decoy[1]), $scenario['decoys']);

        $this->assertNotNull(
            NotificationTarget::of($row),
            "{$class} writes a row NotificationTarget cannot place, so reading what it announces can never clear it.",
        );

        ($scenario['read'])();

        $this->assertNotNull($row->refresh()->read_at, "reading the target left {$class}'s row unread");
        $this->assertNull($theirs->refresh()->read_at, 'one member reading spent another member row');
        foreach ($decoys as $index => $decoy) {
            $this->assertNull($decoy->refresh()->read_at, "the read spent an unrelated row (decoy {$index})");
        }
    }

    public function test_a_mention_the_cursor_has_not_reached_keeps_its_row(): void
    {
        $viewer = Member::factory()->create();
        $author = Member::factory()->create();
        $group = $this->joinedGroup($viewer);
        $first = GroupMessage::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        $second = GroupMessage::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        $rows = collect([$first, $second])->map(fn (GroupMessage $message): DatabaseNotification => $this->seedRow(
            $viewer,
            GroupTalkMentionedNotification::class,
            ['kind' => 'group_talk_mention', 'group_id' => $group->getKey(), 'message_id' => $message->getKey()],
        ));

        $this->actingAs($viewer)
            ->postJson("/groups/{$group->getKey()}/talk/read", ['messageId' => $first->getKey()])
            ->assertNoContent();

        $this->assertNotNull($rows[0]->refresh()->read_at);
        $this->assertNull($rows[1]->refresh()->read_at);

        $this->actingAs($viewer)
            ->postJson("/groups/{$group->getKey()}/talk/read", ['messageId' => $second->getKey()])
            ->assertNoContent();

        $this->assertNotNull($rows[1]->refresh()->read_at);
    }

    public function test_the_conversation_spends_the_rows_its_read_covered(): void
    {
        $viewer = Member::factory()->create();
        $sender = Member::factory()->create();

        $messages = collect(['first', 'second'])->map(function (string $body) use ($viewer, $sender): DirectMessage {
            $message = DirectMessage::factory()->create(['sender_id' => $sender->getKey(), 'body' => $body]);
            DirectMessageRecipient::factory()->create([
                'direct_message_id' => $message->getKey(), 'recipient_id' => $viewer->getKey(),
            ]);

            return $message;
        });

        $rows = $messages->map(fn (DirectMessage $message): DatabaseNotification => $this->seedRow(
            $viewer,
            DirectMessageReceivedNotification::class,
            ['kind' => 'direct_message_received', 'direct_message_id' => $message->getKey()],
        ));

        $this->actingAs($viewer)
            ->postJson("/messages/{$sender->getKey()}/read", ['messageId' => $messages[0]->getKey()])
            ->assertNoContent();

        $this->assertNotNull($rows[0]->refresh()->read_at);
        $this->assertNull($rows[1]->refresh()->read_at);
    }

    /** One requester's row: the other requests on that page are still waiting for an answer of their own. */
    public function test_answering_a_request_spends_that_requester_row_only(): void
    {
        $viewer = Member::factory()->create();
        [$accepted, $waiting] = Member::factory()->count(2)->create()->all();

        foreach ([$accepted, $waiting] as $requester) {
            DB::table('friend_requests')->insert(['requester_id' => $requester->getKey(), 'target_id' => $viewer->getKey()]);
        }

        $rows = collect([$accepted, $waiting])->map(fn (Member $requester): DatabaseNotification => $this->seedRow(
            $viewer,
            FriendRequestedNotification::class,
            ['kind' => 'friend_requested', 'requester_id' => $requester->getKey()],
        ));

        $this->actingAs($viewer)
            ->post('/friend/accept', ['requester_id' => $accepted->getKey()])
            ->assertRedirect();

        $this->assertNotNull($rows[0]->refresh()->read_at);
        $this->assertNull($rows[1]->refresh()->read_at);
    }

    /**
     * @return array{data: array<string, mixed>, decoys: list<array{0: class-string, 1: array<string, mixed>}>, read: Closure}
     */
    private function scenario(string $class, Member $viewer): array
    {
        return match ($class) {
            AdminTransferRequestedNotification::class => $this->groupScenario($class, $viewer, 'group_admin_transfer_requested'),
            GroupJoinedNotification::class => $this->groupScenario($class, $viewer, 'group_joined'),
            SubAdminAppointedNotification::class => $this->groupScenario($class, $viewer, 'group_sub_admin_appointed'),

            TopicCommentedNotification::class, TopicCommentBroadcastNotification::class => $this->topicScenario($class, $viewer, 'group_topic_commented'),
            TopicPostedNotification::class => $this->topicScenario($class, $viewer, 'group_topic_posted'),

            EventCommentedNotification::class, EventCommentBroadcastNotification::class => $this->eventScenario($class, $viewer, 'group_event_commented'),
            EventPostedNotification::class => $this->eventScenario($class, $viewer, 'group_event_posted'),

            DiaryCommentedNotification::class => $this->diaryScenario($class, $viewer, 'diary_commented'),
            DiaryPostedNotification::class => $this->diaryScenario($class, $viewer, 'diary_posted'),

            TimelineMentionedNotification::class => $this->timelineScenario($class, $viewer, 'timeline_mentioned'),
            TimelinePostedNotification::class => $this->timelineScenario($class, $viewer, 'timeline_posted'),
            TimelineRepliedNotification::class => $this->timelineReplyScenario($class, $viewer),

            FriendRequestedNotification::class => $this->friendRequestedScenario($viewer),
            FriendRequestAcceptedNotification::class => $this->profileScenario($class, $viewer),
            DirectMessageReceivedNotification::class => $this->directMessageScenario($class, $viewer),

            GroupTalkMentionedNotification::class => $this->talkMentionScenario($class, $viewer),
            GroupTalkMessagePostedNotification::class => $this->talkRoomScenario($class, $viewer),

            default => $this->fail("{$class} has no scenario here: say what reading its target means, or the row can never be spent by reading."),
        };
    }

    /** @return array{data: array<string, mixed>, decoys: list<array{0: class-string, 1: array<string, mixed>}>, read: Closure} */
    private function groupScenario(string $class, Member $viewer, string $kind): array
    {
        $group = $this->joinedGroup($viewer);
        $elsewhere = Group::factory()->create();

        return [
            'data' => ['kind' => $kind, 'group_id' => $group->getKey()],
            'decoys' => [
                [$class, ['kind' => $kind, 'group_id' => $elsewhere->getKey()]],
                // The room's row belongs to the conversation, not to the page the room hangs off.
                [GroupTalkMessagePostedNotification::class, ['kind' => 'group_talk_new_message', 'group_id' => $group->getKey(), 'message_id' => 1]],
            ],
            'read' => fn () => $this->actingAs($viewer)->get("/groups/{$group->getKey()}")->assertOk(),
        ];
    }

    /** @return array{data: array<string, mixed>, decoys: list<array{0: class-string, 1: array<string, mixed>}>, read: Closure} */
    private function topicScenario(string $class, Member $viewer, string $kind): array
    {
        $group = $this->joinedGroup($viewer);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey()]);
        $elsewhere = GroupTopic::factory()->create(['group_id' => $group->getKey()]);

        return [
            'data' => ['kind' => $kind, 'topic_id' => $topic->getKey()],
            'decoys' => [[$class, ['kind' => $kind, 'topic_id' => $elsewhere->getKey()]]],
            'read' => fn () => $this->actingAs($viewer)->get("/topics/{$topic->getKey()}")->assertOk(),
        ];
    }

    /** @return array{data: array<string, mixed>, decoys: list<array{0: class-string, 1: array<string, mixed>}>, read: Closure} */
    private function eventScenario(string $class, Member $viewer, string $kind): array
    {
        $group = $this->joinedGroup($viewer);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey()]);
        $elsewhere = GroupEvent::factory()->create(['group_id' => $group->getKey()]);

        return [
            'data' => ['kind' => $kind, 'event_id' => $event->getKey()],
            'decoys' => [[$class, ['kind' => $kind, 'event_id' => $elsewhere->getKey()]]],
            'read' => fn () => $this->actingAs($viewer)->get("/events/{$event->getKey()}")->assertOk(),
        ];
    }

    /** @return array{data: array<string, mixed>, decoys: list<array{0: class-string, 1: array<string, mixed>}>, read: Closure} */
    private function diaryScenario(string $class, Member $viewer, string $kind): array
    {
        $diary = Diary::factory()->create();
        $elsewhere = Diary::factory()->create();

        return [
            'data' => ['kind' => $kind, 'diary_id' => $diary->getKey()],
            'decoys' => [[$class, ['kind' => $kind, 'diary_id' => $elsewhere->getKey()]]],
            'read' => fn () => $this->actingAs($viewer)->get("/diary/{$diary->getKey()}")->assertOk(),
        ];
    }

    /** @return array{data: array<string, mixed>, decoys: list<array{0: class-string, 1: array<string, mixed>}>, read: Closure} */
    private function timelineScenario(string $class, Member $viewer, string $kind): array
    {
        $post = TimelinePost::factory()->create();
        $elsewhere = TimelinePost::factory()->create();

        return [
            'data' => ['kind' => $kind, 'post_id' => $post->getKey()],
            'decoys' => [[$class, ['kind' => $kind, 'post_id' => $elsewhere->getKey()]]],
            'read' => fn () => $this->actingAs($viewer)->get("/timeline/{$post->getKey()}")->assertOk(),
        ];
    }

    private function timelineReplyScenario(string $class, Member $viewer): array
    {
        $post = TimelinePost::factory()->create();
        $reply = TimelinePost::factory()->replyTo($post)->create();
        $elsewhere = TimelinePost::factory()->replyTo(TimelinePost::factory()->create())->create();

        return [
            'data' => ['kind' => 'timeline_replied', 'post_id' => $reply->getKey()],
            'decoys' => [[$class, ['kind' => 'timeline_replied', 'post_id' => $elsewhere->getKey()]]],
            'read' => fn () => $this->actingAs($viewer)->get("/timeline/{$post->getKey()}")->assertOk(),
        ];
    }

    /** @return array{data: array<string, mixed>, decoys: list<array{0: class-string, 1: array<string, mixed>}>, read: Closure} */
    private function profileScenario(string $class, Member $viewer): array
    {
        $accepter = Member::factory()->create();
        $elsewhere = Member::factory()->create();

        return [
            'data' => ['kind' => 'friend_request_accepted', 'accepter_id' => $accepter->getKey()],
            'decoys' => [[$class, ['kind' => 'friend_request_accepted', 'accepter_id' => $elsewhere->getKey()]]],
            'read' => fn () => $this->actingAs($viewer)->get("/member/{$accepter->getKey()}")->assertOk(),
        ];
    }

    /**
     * The requests page is every request's target, so the decoy is a row of another kind.
     *
     * @return array{data: array<string, mixed>, decoys: list<array{0: class-string, 1: array<string, mixed>}>, read: Closure}
     */
    private function friendRequestedScenario(Member $viewer): array
    {
        $requester = Member::factory()->create();
        $diary = Diary::factory()->create();

        return [
            'data' => ['kind' => 'friend_requested', 'requester_id' => $requester->getKey()],
            'decoys' => [[DiaryPostedNotification::class, ['kind' => 'diary_posted', 'diary_id' => $diary->getKey()]]],
            'read' => fn () => $this->actingAs($viewer)->get('/friend/requests')->assertOk(),
        ];
    }

    /** @return array{data: array<string, mixed>, decoys: list<array{0: class-string, 1: array<string, mixed>}>, read: Closure} */
    private function directMessageScenario(string $class, Member $viewer): array
    {
        // The mailbox's own read page, which Modern folds into the conversation (covered separately).
        config(['openpne.surface_mode' => 'classic_default']);

        $sender = Member::factory()->create();
        $message = DirectMessage::factory()->create(['sender_id' => $sender->getKey()]);
        $elsewhere = DirectMessage::factory()->create(['sender_id' => $sender->getKey()]);
        foreach ([$message, $elsewhere] as $delivered) {
            DirectMessageRecipient::factory()->create([
                'direct_message_id' => $delivered->getKey(), 'recipient_id' => $viewer->getKey(),
            ]);
        }

        return [
            'data' => ['kind' => 'direct_message_received', 'direct_message_id' => $message->getKey()],
            'decoys' => [[$class, ['kind' => 'direct_message_received', 'direct_message_id' => $elsewhere->getKey()]]],
            'read' => fn () => $this->actingAs($viewer)->get("/message/read/{$message->getKey()}")->assertOk(),
        ];
    }

    /** @return array{data: array<string, mixed>, decoys: list<array{0: class-string, 1: array<string, mixed>}>, read: Closure} */
    private function talkMentionScenario(string $class, Member $viewer): array
    {
        $group = $this->joinedGroup($viewer);
        $message = GroupMessage::factory()->create(['group_id' => $group->getKey()]);
        $elsewhere = GroupMessage::factory()->create();

        return [
            'data' => ['kind' => 'group_talk_mention', 'group_id' => $group->getKey(), 'message_id' => $message->getKey()],
            'decoys' => [[$class, ['kind' => 'group_talk_mention', 'group_id' => $elsewhere->group_id, 'message_id' => $elsewhere->getKey()]]],
            'read' => fn () => $this->actingAs($viewer)
                ->postJson("/groups/{$group->getKey()}/talk/read", ['messageId' => $message->getKey()])
                ->assertNoContent(),
        ];
    }

    /** @return array{data: array<string, mixed>, decoys: list<array{0: class-string, 1: array<string, mixed>}>, read: Closure} */
    private function talkRoomScenario(string $class, Member $viewer): array
    {
        $group = $this->joinedGroup($viewer);
        $elsewhere = Group::factory()->create();
        $message = GroupMessage::factory()->create(['group_id' => $group->getKey()]);

        return [
            'data' => ['kind' => 'group_talk_new_message', 'group_id' => $group->getKey(), 'message_id' => $message->getKey()],
            'decoys' => [[$class, ['kind' => 'group_talk_new_message', 'group_id' => $elsewhere->getKey(), 'message_id' => 1]]],
            'read' => fn () => $this->actingAs($viewer)
                ->postJson("/groups/{$group->getKey()}/talk/read", ['messageId' => $message->getKey()])
                ->assertNoContent(),
        ];
    }

    private function joinedGroup(Member $viewer): Group
    {
        $group = Group::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $viewer->getKey()]);

        return $group;
    }

    /** @param array<string, mixed> $data */
    private function seedRow(Member $member, string $type, array $data): DatabaseNotification
    {
        /** @var DatabaseNotification $row */
        $row = $member->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => $type,
            'data' => $data,
            'read_at' => null,
        ]);

        return $row;
    }
}
