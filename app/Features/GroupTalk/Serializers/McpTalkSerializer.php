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
 * What the MCP tools put on the wire. A separate shape from the Modern surface's
 * ({@see GroupMessageSerializer}, {@see TalkRoomSerializer}) rather than a reuse of it: those carry
 * `/file` and `/cache/img` URLs, which a bearer client cannot fetch — the file routes are
 * session-guarded — so shipping them would be shipping links that always 404.
 *
 * Text is all it carries. Pictures are reported as a count, so a reader knows the message is not
 * only what it says, and the bytes are out of scope until the file routes speak this realm.
 */
class McpTalkSerializer
{
    /**
     * @return array{id: int, body: string, authorId: int|null, authorName: string|null, authorIsAi: bool, createdAt: string, cursor: string, hasImages: bool, imageCount: int, mentions: list<int>}
     */
    public static function message(GroupMessage $message): array
    {
        $images = $message->images->count();

        return [
            'id' => (int) $message->getKey(),
            'body' => $message->body,
            // Null for a withdrawn author (the FK sets it null), which is a fact about the row rather
            // than a gap to paper over.
            'authorId' => $message->author?->getKey(),
            'authorName' => $message->author?->name,
            // A reading agent gets the same answer a reader's eye does off the chip. False for a
            // withdrawn author: there is no account left to be one.
            'authorIsAi' => (bool) $message->author?->isAiAccount(),
            'createdAt' => CarbonImmutable::instance($message->created_at)->toIso8601String(),
            'cursor' => (string) GroupTalkCursor::of($message),
            'hasImages' => $images > 0,
            'imageCount' => $images,
            'mentions' => $message->mentions
                ->map(fn (GroupMessageMention $mention): int => (int) $mention->member_id)
                ->values()
                ->all(),
        ];
    }

    /**
     * A page of the conversation, oldest first. `nextCursor` / `previousCursor` are the two positions
     * a caller asks the next page from, lifted out of the rows so a client never has to know which end
     * of the list to read them off.
     *
     * @return array{messages: list<array<string, mixed>>, hasOlder: bool, hasNewer: bool, previousCursor: string|null, nextCursor: string|null}
     */
    public static function page(GroupTalkPage $page): array
    {
        $messages = $page->messages->map([self::class, 'message'])->values()->all();

        return [
            'messages' => $messages,
            'hasOlder' => $page->hasOlder,
            'hasNewer' => $page->hasNewer,
            'previousCursor' => $messages === [] ? null : $messages[0]['cursor'],
            'nextCursor' => $messages === [] ? null : $messages[count($messages) - 1]['cursor'],
        ];
    }

    /**
     * `unreadMentions` is how many of `unread` name the caller — the number a polling agent reads to
     * decide whether a room wants an answer, without paging the messages to find out. Null only for
     * a read that did not ask for it; the tool always does.
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
