<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Features\GroupTopic\TopicReadAccess;
use App\Features\Notifications\NotificationPreview;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\DirectMessage;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\GroupMember;
use App\Models\GroupMessage;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\Member;
use App\Models\TimelinePost;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotificationPreviewTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: Closure(Member): array{0: array<string, mixed>, 1: string}}> */
    public static function kinds(): array
    {
        return [
            'diary_posted' => [fn (Member $recipient): array => [
                ['kind' => 'diary_posted', 'diary_id' => Diary::factory()->create(['title' => 'Lunch', 'body' => "First\nSecond"])->getKey()],
                "Lunch\nFirst Second",
            ]],
            'diary_commented' => [fn (Member $recipient): array => [
                ['kind' => 'diary_commented', 'comment_id' => DiaryComment::factory()->create(['body' => 'Looks good'])->getKey()],
                'Looks good',
            ]],
            'direct_message_received' => [function (Member $recipient): array {
                $message = DirectMessage::factory()->create(['body' => 'See you']);
                $message->recipients()->create(['recipient_id' => $recipient->getKey()]);

                return [['kind' => 'direct_message_received', 'direct_message_id' => $message->getKey()], 'See you'];
            }],
            'group_talk_mention' => [fn (Member $recipient): array => [
                ['kind' => 'group_talk_mention', 'message_id' => GroupMessage::factory()->create(['group_id' => self::joinedGroup($recipient)->getKey(), 'body' => 'Ping'])->getKey()],
                'Ping',
            ]],
            'group_talk_new_message' => [fn (Member $recipient): array => [
                ['kind' => 'group_talk_new_message', 'message_id' => GroupMessage::factory()->create(['group_id' => self::joinedGroup($recipient)->getKey(), 'body' => 'Hello room'])->getKey()],
                'Hello room',
            ]],
            'group_topic_posted' => [fn (Member $recipient): array => [
                ['kind' => 'group_topic_posted', 'topic_id' => GroupTopic::factory()->create(['group_id' => self::joinedGroup($recipient)->getKey(), 'name' => 'Agenda', 'body' => 'Items'])->getKey()],
                "Agenda\nItems",
            ]],
            'group_event_posted' => [fn (Member $recipient): array => [
                ['kind' => 'group_event_posted', 'event_id' => GroupEvent::factory()->create(['group_id' => self::joinedGroup($recipient)->getKey(), 'name' => 'Meetup', 'body' => 'At noon'])->getKey()],
                "Meetup\nAt noon",
            ]],
            'group_topic_commented' => [function (Member $recipient): array {
                $topic = GroupTopic::factory()->create(['group_id' => self::joinedGroup($recipient)->getKey()]);

                return [
                    ['kind' => 'group_topic_commented', 'comment_id' => GroupTopicComment::factory()->create(['group_topic_id' => $topic->getKey(), 'body' => 'Agreed'])->getKey()],
                    'Agreed',
                ];
            }],
            'group_event_commented' => [function (Member $recipient): array {
                $event = GroupEvent::factory()->create(['group_id' => self::joinedGroup($recipient)->getKey()]);

                return [
                    ['kind' => 'group_event_commented', 'comment_id' => GroupEventComment::factory()->create(['group_event_id' => $event->getKey(), 'body' => 'Coming'])->getKey()],
                    'Coming',
                ];
            }],
            'timeline_posted' => [fn (Member $recipient): array => [
                ['kind' => 'timeline_posted', 'post_id' => TimelinePost::factory()->create(['body' => 'Out now'])->getKey()],
                'Out now',
            ]],
            'timeline_replied' => [fn (Member $recipient): array => [
                ['kind' => 'timeline_replied', 'post_id' => TimelinePost::factory()->replyTo(TimelinePost::factory()->create())->create(['body' => 'Same here'])->getKey()],
                'Same here',
            ]],
        ];
    }

    /** @param Closure(Member): array{0: array<string, mixed>, 1: string} $scenario */
    #[DataProvider('kinds')]
    public function test_every_kind_with_content_previews_it(Closure $scenario): void
    {
        $recipient = Member::factory()->create();
        [$data, $expected] = $scenario($recipient);

        $this->assertSame($expected, NotificationPreview::for($data['kind'], $data, $recipient));
    }

    public function test_a_room_the_recipient_left_is_not_quoted(): void
    {
        $recipient = Member::factory()->create();
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        $message = GroupMessage::factory()->create(['group_id' => $group->getKey(), 'body' => 'Private talk']);

        $this->assertNull(NotificationPreview::for('group_talk_new_message', ['kind' => 'group_talk_new_message', 'message_id' => $message->getKey()], $recipient));
    }

    public function test_a_missing_or_malformed_id_previews_nothing(): void
    {
        $recipient = Member::factory()->create();

        $this->assertNull(NotificationPreview::for('diary_posted', ['kind' => 'diary_posted'], $recipient));
        $this->assertNull(NotificationPreview::for('diary_posted', ['kind' => 'diary_posted', 'diary_id' => 'abc'], $recipient));
    }

    private static function joinedGroup(Member $member): Group
    {
        $group = Group::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);

        return $group;
    }
}
