<?php

namespace App\Features\DirectMessage\Serializers;

use App\Features\DirectMessage\ConversationCursor;
use App\Features\DirectMessage\ConversationPage;
use App\Files\AttachmentShape;
use App\Files\PostAttachments;
use App\Models\DirectMessage;
use App\Models\Member;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * `author` is null for a withdrawn member and `subject` is null for a message written as chat, so
 * the client draws neither rather than an empty one. Every message carries its own `cursor`, which
 * is what the client hands back to ask for the pages around it.
 */
class ConversationMessageSerializer
{
    /**
     * @return array{id: int, cursor: string, body: string, subject: string|null, createdAt: string, author: array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool}|null, isOwn: bool, images: list<array{id: int, url: string, thumbnailUrl: string, fitSources: list<array{url: string, box: int}>, cropSources: array{tall?: list<array{url: string, width: int}>, wide?: list<array{url: string, width: int}>}, width: int|null, height: int|null, animatedSources: list<array{url: string, box: int}>}>}
     */
    public static function message(DirectMessage $message, Member $viewer): array
    {
        return [
            'id' => (int) $message->getKey(),
            'cursor' => (string) ConversationCursor::of($message),
            // A legacy row may hold a null body (subject only), which is a message all the same.
            'body' => (string) $message->body,
            'subject' => $message->subject === null ? null : (string) $message->subject,
            'createdAt' => self::instant($message->created_at),
            'author' => $message->sender === null ? null : DirectMessageSerializer::memberRef($message->sender),
            'isOwn' => (int) $message->sender_id === (int) $viewer->getKey(),
            'images' => PostAttachments::partition($message->files)['images']->map([DirectMessageSerializer::class, 'image'])->values()->all(),
            'files' => PostAttachments::partition($message->files)['files']->map([AttachmentShape::class, 'of'])->values()->all(),
        ];
    }

    /**
     * @return array{messages: list<array>, hasOlder: bool, hasNewer: bool}
     */
    public static function page(ConversationPage $page, Member $viewer): array
    {
        return [
            'messages' => $page->messages
                ->map(fn (DirectMessage $message): array => self::message($message, $viewer))
                ->values()
                ->all(),
            'hasOlder' => $page->hasOlder,
            'hasNewer' => $page->hasNewer,
        ];
    }

    /**
     * @param  array{count: int, at: CarbonImmutable, id: int}|null  $snapshot
     * @return array{count: int, firstUnread: array{at: string, id: int}, cursor: string}|null
     */
    public static function unreadSnapshot(?array $snapshot): ?array
    {
        if ($snapshot === null) {
            return null;
        }

        return [
            'count' => $snapshot['count'],
            'firstUnread' => ['at' => self::instant($snapshot['at']), 'id' => $snapshot['id']],
            'cursor' => (string) new ConversationCursor($snapshot['at'], $snapshot['id']),
        ];
    }

    /** The one wire form of an instant here, since the client compares the positions directly. */
    public static function instant(DateTimeInterface $at): string
    {
        return CarbonImmutable::instance($at)->toIso8601String();
    }
}
