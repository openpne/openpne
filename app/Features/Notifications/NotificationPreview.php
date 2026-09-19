<?php

declare(strict_types=1);

namespace App\Features\Notifications;

use App\Features\Diary\DiaryAccess;
use App\Features\GroupEvent\GroupEventAccess;
use App\Features\GroupTalk\GroupTalkAccess;
use App\Features\GroupTopic\GroupTopicAccess;
use App\Features\Timeline\TimelineAccess;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\DirectMessage;
use App\Models\DirectMessageRecipient;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\GroupMessage;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\BodyRenderer;
use App\Support\BodyText;
use App\Support\ChatPreview;

/**
 * Resolved at send time from the row's ids, so content the recipient can no longer read — deleted,
 * or hidden from them since the row was written — yields null rather than a stale copy
 * (docs/internals/notifications.md, "Web push").
 */
final class NotificationPreview
{
    /** @param array<string, mixed> $data */
    public static function for(?string $kind, array $data, Member $recipient): ?string
    {
        return match ($kind) {
            'diary_posted' => self::diary(self::id($data, 'diary_id'), $recipient),
            'diary_commented' => self::diaryComment(self::id($data, 'comment_id'), $recipient),
            'direct_message_received' => self::directMessage(self::id($data, 'direct_message_id'), $recipient),
            'group_talk_mention', 'group_talk_new_message' => self::talkMessage(self::id($data, 'message_id'), $recipient),
            'group_topic_posted' => self::topic(self::id($data, 'topic_id'), $recipient),
            'group_event_posted' => self::event(self::id($data, 'event_id'), $recipient),
            'group_topic_commented' => self::topicComment(self::id($data, 'comment_id'), $recipient),
            'group_event_commented' => self::eventComment(self::id($data, 'comment_id'), $recipient),
            'timeline_posted', 'timeline_mentioned', 'timeline_replied' => self::timelinePost(self::id($data, 'post_id'), $recipient),
            default => null,
        };
    }

    /** @param array<string, mixed> $data */
    private static function id(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    private static function diary(?int $id, Member $recipient): ?string
    {
        $diary = $id === null ? null : Diary::find($id);
        if ($diary === null || ! DiaryAccess::canView($recipient, $diary)) {
            return null;
        }

        return self::titled($diary->title, BodyRenderer::excerpt($diary->body, $diary->format));
    }

    private static function diaryComment(?int $id, Member $recipient): ?string
    {
        $comment = $id === null ? null : DiaryComment::with('diary')->find($id);
        if ($comment === null || $comment->diary === null || ! DiaryAccess::canView($recipient, $comment->diary)) {
            return null;
        }

        return self::plain($comment->body, $comment->images()->exists());
    }

    private static function directMessage(?int $id, Member $recipient): ?string
    {
        $message = $id === null ? null : DirectMessage::find($id);
        if ($message === null) {
            return null;
        }

        $stillInInbox = DirectMessageRecipient::query()->ofDelivered()->recipientLive()
            ->where('recipient_id', $recipient->getKey())
            ->where('direct_message_id', $message->getKey())
            ->exists();
        if (! $stillInInbox) {
            return null;
        }

        return self::plain($message->body, $message->files()->exists(), $message->subject);
    }

    private static function talkMessage(?int $id, Member $recipient): ?string
    {
        $message = $id === null ? null : GroupMessage::with('group')->find($id);
        if ($message === null || $message->group === null || ! GroupTalkAccess::canView($message->group, $recipient)) {
            return null;
        }

        return self::plain($message->body, $message->images()->exists());
    }

    private static function topic(?int $id, Member $recipient): ?string
    {
        $topic = $id === null ? null : GroupTopic::find($id);
        if ($topic === null || ! GroupTopicAccess::canViewTopic($topic, $recipient)) {
            return null;
        }

        return self::titled($topic->name, BodyRenderer::excerpt($topic->body, $topic->format));
    }

    private static function event(?int $id, Member $recipient): ?string
    {
        $event = $id === null ? null : GroupEvent::find($id);
        if ($event === null || ! GroupEventAccess::canViewEvent($event, $recipient)) {
            return null;
        }

        return self::titled($event->name, BodyRenderer::excerpt($event->body, $event->format));
    }

    private static function topicComment(?int $id, Member $recipient): ?string
    {
        $comment = $id === null ? null : GroupTopicComment::with('topic')->find($id);
        if ($comment === null || $comment->topic === null || ! GroupTopicAccess::canViewTopic($comment->topic, $recipient)) {
            return null;
        }

        return self::plain($comment->body, $comment->images()->exists());
    }

    private static function eventComment(?int $id, Member $recipient): ?string
    {
        $comment = $id === null ? null : GroupEventComment::with('event')->find($id);
        if ($comment === null || $comment->event === null || ! GroupEventAccess::canViewEvent($comment->event, $recipient)) {
            return null;
        }

        return self::plain($comment->body, $comment->images()->exists());
    }

    private static function timelinePost(?int $id, Member $recipient): ?string
    {
        $post = $id === null ? null : TimelinePost::find($id);
        $root = $post === null || $post->in_reply_to_id === null ? $post : $post->parent;
        if ($post === null || $root === null || ! TimelineAccess::canView($recipient, $root)) {
            return null;
        }

        return self::plain($post->body, $post->images()->exists());
    }

    private static function titled(?string $title, string $excerpt): ?string
    {
        $lines = array_filter([BodyText::truncateToRows($title), $excerpt], static fn (string $line): bool => $line !== '');

        return $lines === [] ? null : implode("\n", $lines);
    }

    private static function plain(?string $body, bool $hasImages, ?string $fallback = null): ?string
    {
        $line = ChatPreview::lineOrImages([BodyText::excerpt($body), (string) $fallback], $hasImages);

        return $line === '' ? null : $line;
    }
}
