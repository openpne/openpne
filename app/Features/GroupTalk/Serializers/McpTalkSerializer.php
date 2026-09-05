<?php

declare(strict_types=1);

namespace App\Features\GroupTalk\Serializers;

use App\Features\GroupTalk\GroupTalkCursor;
use App\Features\GroupTalk\GroupTalkPage;
use App\Features\GroupTalk\TalkRoom;
use App\Models\GroupMessage;
use App\Models\GroupMessageMention;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * A separate shape from the Modern surface's ({@see GroupMessageSerializer}, {@see TalkRoomSerializer})
 * rather than a reuse of it: those carry `/file` and `/cache/img` URLs, which a bearer client cannot
 * fetch because the file routes are session-guarded. Pictures are therefore reported as a count, and
 * their bytes are fetched by naming the message.
 */
class McpTalkSerializer
{
    /**
     * @param  array<int, GroupMessage>  $parents
     * @return array{id: int, body: string, authorId: int|null, authorName: string|null, authorIsAi: bool, createdAt: string, cursor: string, hasImages: bool, imageCount: int, mentions: list<int>, inReplyTo: array{id: int, authorId: int|null}|null}
     */
    public static function message(GroupMessage $message, array $parents): array
    {
        $images = $message->images->count();

        return [
            'id' => (int) $message->getKey(),
            'body' => $message->body,
            'authorId' => $message->author?->getKey(),
            'authorName' => $message->author?->name,
            'authorIsAi' => (bool) $message->author?->isAiAccount(),
            'createdAt' => CarbonImmutable::instance($message->created_at)->toIso8601String(),
            'cursor' => (string) GroupTalkCursor::of($message),
            'hasImages' => $images > 0,
            'imageCount' => $images,
            'mentions' => $message->mentions
                ->map(fn (GroupMessageMention $mention): int => (int) $mention->member_id)
                ->values()
                ->all(),
            'inReplyTo' => self::inReplyTo($message, $parents),
        ];
    }

    /**
     * `authorId` is null when there is nobody behind the parent — deleted, or its author withdrawn —
     * and the two are deliberately not distinguished: in both cases there is nobody to answer.
     *
     * @param  array<int, GroupMessage>  $parents
     * @return array{id: int, authorId: int|null}|null
     */
    private static function inReplyTo(GroupMessage $message, array $parents): ?array
    {
        if ($message->in_reply_to_id === null) {
            return null;
        }

        $author = ($parents[(int) $message->in_reply_to_id] ?? null)?->member_id;

        return ['id' => (int) $message->in_reply_to_id, 'authorId' => $author === null ? null : (int) $author];
    }

    /**
     * `nextCursor` / `previousCursor` are lifted out of the rows so a client never has to know which
     * end of the list to read them off.
     *
     * @param  array<int, GroupMessage>  $parents
     * @return array{messages: list<array<string, mixed>>, hasOlder: bool, hasNewer: bool, previousCursor: string|null, nextCursor: string|null}
     */
    public static function page(GroupTalkPage $page, array $parents): array
    {
        $messages = $page->messages->map(fn (GroupMessage $message): array => self::message($message, $parents))->values()->all();

        return [
            'messages' => $messages,
            'hasOlder' => $page->hasOlder,
            'hasNewer' => $page->hasNewer,
            'previousCursor' => $messages === [] ? null : $messages[0]['cursor'],
            'nextCursor' => $messages === [] ? null : $messages[count($messages) - 1]['cursor'],
        ];
    }

    /**
     * `unreadMentions` counts the unread that name the caller or answer something they said, and is
     * null only for a read that did not ask for it.
     *
     * @return array{groupId: int, name: string, unread: int, unreadMentions: int|null, muted: bool, lastMessageAt: string|null}
     */
    public static function room(TalkRoom $room): array
    {
        return [
            'groupId' => (int) $room->group->getKey(),
            'name' => $room->group->name,
            'unread' => $room->unread,
            'unreadMentions' => $room->unreadMentions,
            'muted' => $room->muted,
            'lastMessageAt' => $room->latest === null
                ? null
                : CarbonImmutable::instance($room->latest->created_at)->toIso8601String(),
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, TalkRoom>  $paginator
     * @return array{rooms: list<array<string, mixed>>, page: int, lastPage: int, total: int}
     */
    public static function rooms(LengthAwarePaginator $paginator): array
    {
        return [
            'rooms' => array_map([self::class, 'room'], $paginator->items()),
            'page' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ];
    }
}
