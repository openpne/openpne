<?php

namespace App\Features\DirectMessage\Serializers;

use App\Features\DirectMessage\ConversationSummary;
use App\Models\DirectMessage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * Modern surface shape for the conversation list. `counterpart` is null for the withdrawn bucket,
 * which the client addresses by its own literal — the row needs no id of its own.
 */
class ConversationListSerializer
{
    /**
     * How much of a body travels. The row shows one line and clips it in CSS, so this is a payload
     * bound, not the visible truncation. Nothing is appended: the ellipsis belongs to the clip.
     */
    private const PREVIEW_LIMIT = 140;

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
     * The line the row leads with: the message's body, or its subject when a mailbox message carries
     * only one (a message written as chat has no subject, and an upgraded one may have no body).
     */
    private static function preview(DirectMessage $latest): string
    {
        $body = self::oneLine((string) $latest->body);

        return $body === '' ? self::oneLine((string) $latest->subject) : $body;
    }

    /** One line: every run of whitespace becomes a single space, so a multi-line body cannot grow the row. */
    private static function oneLine(string $text): string
    {
        return Str::limit(trim(preg_replace('/\s+/u', ' ', $text) ?? $text), self::PREVIEW_LIMIT, '');
    }
}
