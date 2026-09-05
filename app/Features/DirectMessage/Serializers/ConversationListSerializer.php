<?php

namespace App\Features\DirectMessage\Serializers;

use App\Features\DirectMessage\ConversationSummary;
use App\Models\DirectMessage;
use App\Support\ChatPreview;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * `counterpart` is null for the withdrawn bucket, which the client addresses by its own literal.
 */
class ConversationListSerializer
{
    /**
     * No author on the preview: the row's title already names the only other person in a 1:1
     * conversation, so an attribution line would only ever repeat it (or name the viewer).
     *
     * @return array{counterpart: array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool}|null, unread: int, latest: array{body: string, createdAt: string}}
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
     * An upgraded row may hold no body and a message written as chat no subject, so both are offered
     * before the picture stand-in. `ConversationList` supplies `files_exists`.
     */
    private static function preview(DirectMessage $latest): string
    {
        return ChatPreview::lineOrImages(
            [(string) $latest->body, (string) $latest->subject],
            (bool) $latest->files_exists,
        );
    }
}
