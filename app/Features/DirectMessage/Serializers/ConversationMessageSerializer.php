<?php

namespace App\Features\DirectMessage\Serializers;

use App\Features\DirectMessage\ConversationCursor;
use App\Features\DirectMessage\ConversationPage;
use App\Models\DirectMessage;
use App\Models\DirectMessageRecipient;
use App\Models\Member;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The chat shape of a stored message. `author` is null for a withdrawn member (the FK sets it null)
 * and the client renders the established "Withdrawn member" label; `subject` is null for a message
 * written as chat and a string for one that came from the mailbox, so the client can leave the line
 * out rather than draw an empty one.
 *
 * Every message carries its `cursor` — its position in the keyset order — which is what the client
 * hands back to ask for the pages around it.
 */
class ConversationMessageSerializer
{
    /**
     * @return array{id: int, cursor: string, body: string, subject: string|null, createdAt: string, author: array{id: int, name: string, imageUrl: string|null, avatarColor: string|null}|null, isOwn: bool, read: bool|null, images: list<array{id: int, url: string, thumbnailUrl: string}>}
     */
    public static function message(DirectMessage $message, Member $viewer, ?Member $counterpart): array
    {
        $viewerId = (int) $viewer->getKey();

        return [
            'id' => (int) $message->getKey(),
            'cursor' => (string) ConversationCursor::of($message),
            // A legacy row may hold a null body (subject only), which is a message all the same.
            'body' => (string) $message->body,
            'subject' => $message->subject === null ? null : (string) $message->subject,
            'createdAt' => self::instant($message->created_at),
            'author' => $message->sender === null ? null : DirectMessageSerializer::memberRef($message->sender),
            'isOwn' => (int) $message->sender_id === $viewerId,
            'read' => self::read($message, $viewerId, $counterpart),
            'images' => $message->files->map([DirectMessageSerializer::class, 'image'])->values()->all(),
        ];
    }

    /**
     * A page of the conversation, oldest first, and what lies either side of it.
     *
     * @return array{messages: list<array>, hasOlder: bool, hasNewer: bool}
     */
    public static function page(ConversationPage $page, Member $viewer, ?Member $counterpart): array
    {
        return [
            'messages' => $page->messages
                ->map(fn (DirectMessage $message): array => self::message($message, $viewer, $counterpart))
                ->values()
                ->all(),
            'hasOlder' => $page->hasOlder,
            'hasNewer' => $page->hasNewer,
        ];
    }

    /**
     * Whether the counterpart has opened one of the viewer's own messages: null when there is nothing
     * to report — a message the viewer received, or one in the withdrawn bucket, where no receipt
     * names a member.
     *
     * An upgraded OpenPNE 3 send may carry several receipts, and each recipient reads it in their own
     * conversation, so the answer is **this** conversation's receipt rather than whichever one the
     * relation happens to hold first.
     */
    private static function read(DirectMessage $message, int $viewer, ?Member $counterpart): ?bool
    {
        if ($counterpart === null || (int) $message->sender_id !== $viewer) {
            return null;
        }

        $receipt = $message->recipients->first(
            fn (DirectMessageRecipient $r): bool => (int) $r->recipient_id === (int) $counterpart->getKey()
        );

        return $receipt === null ? null : $receipt->read_at !== null;
    }

    /** The one wire form of an instant in a conversation, so every position the client compares is one shape. */
    public static function instant(DateTimeInterface $at): string
    {
        return CarbonImmutable::instance($at)->toIso8601String();
    }
}
