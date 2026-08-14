<?php

namespace App\Features\DirectMessage\Serializers;

use App\Features\DirectMessage\ConversationSummary;
use App\Models\DirectMessage;
use App\Support\ChatPreview;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Modern surface shape for the conversation list. `counterpart` is null for the withdrawn bucket,
 * which the client addresses by its own literal — the row needs no id of its own.
 */
class ConversationListSerializer
{
    /**
     * No author on the preview: the row's title already names the only other person in a 1:1
     * conversation, so an attribution line would only ever repeat it (or name the viewer).
     *
     * @return array{counterpart: array{id: int, name: string, imageUrl: string|null, avatarColor: string|null}|null, unread: int, latest: array{body: string, createdAt: string}}
     */
    public static function conversation(ConversationSummary $row): array
    {
        $latest = $row->latest;

        return [
            'counterpart' => $row->counterpart === null ? null : DirectMessageSerializer::memberRef($row->counterpart),
            'unread' => $row->unread,
            'latest' => [
                'body' => self::preview($latest),
                'createdAt' => $latest->created_at->toIso8601String(),
            ],
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, ConversationSummary>  $paginator
     * @return array{data: list<array>, meta: array{currentPage: int, lastPage: int, perPage: int, total: int}}
     */
    public static function paginator(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map([self::class, 'conversation'], $paginator->items()),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * The line the row leads with: the message's body, its subject when a mailbox message carries
     * only one (a message written as chat has no subject, and an upgraded one may have no body), and
     * failing both a message with nothing but pictures saying so. ConversationList supplies
     * `files_exists`.
     */
    private static function preview(DirectMessage $latest): string
    {
        return ChatPreview::line((string) $latest->body)
            ?: ChatPreview::line((string) $latest->subject)
            ?: ChatPreview::imagesLine((bool) $latest->files_exists);
    }
}
