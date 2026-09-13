<?php

namespace App\Features\DirectMessage\Serializers;

use App\Features\DirectMessage\DirectMessageListItem;
use App\Features\Member\Serializers\MemberRefSerializer;
use App\Files\ImageLadder;
use App\Models\DirectMessage;
use App\Models\DirectMessageFile;
use App\Models\Member;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * `counterparty` / `recipient` is null for a withdrawn member, a deleted account leaving the row.
 */
class DirectMessageSerializer
{
    /**
     * @return array{id: int, counterparty: array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool}|null, subject: string, date: string, unread: bool}
     */
    public static function row(DirectMessageListItem $item): array
    {
        return [
            'id' => $item->messageId,
            'counterparty' => self::member($item->counterparty),
            'subject' => $item->subject,
            'date' => $item->date->toIso8601String(),
            'unread' => $item->unread,
        ];
    }

    /**
     * Callers eager-load `files.file` and `draftRecipient`.
     *
     * @return array{id: int, subject: string, body: string, recipient: array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool}|null, images: list<array{id: int, url: string, thumbnailUrl: string, fitSources: list<array{url: string, box: int}>, cropSources: array{tall?: list<array{url: string, width: int}>, wide?: list<array{url: string, width: int}>}, width: int|null, height: int|null, animatedSources: list<array{url: string, box: int}>}>}
     */
    public static function draftForm(DirectMessage $draft): array
    {
        return [
            'id' => $draft->getKey(),
            'subject' => (string) $draft->subject,
            'body' => (string) $draft->body,
            'recipient' => self::member($draft->draftRecipient),
            'images' => $draft->files->map([self::class, 'image'])->all(),
        ];
    }

    /**
     * The thumbnail sources a surface picks from (`docs/internals/images.md`, "The two ladders"). A
     * row whose File is gone yields empty urls rather than throwing.
     *
     * @return array{id: int, url: string, thumbnailUrl: string, fitSources: list<array{url: string, box: int}>, cropSources: array{tall?: list<array{url: string, width: int}>, wide?: list<array{url: string, width: int}>}, width: int|null, height: int|null, animatedSources: list<array{url: string, box: int}>}
     */
    public static function image(DirectMessageFile $image): array
    {
        $file = $image->file;

        return [
            'id' => $image->getKey(),
            'url' => $file?->url() ?? '',
            ...ImageLadder::of($file),
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, DirectMessageListItem>  $paginator
     * @return array{data: list<array>, meta: array{currentPage: int, lastPage: int, perPage: int, total: int}}
     */
    public static function paginator(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map([self::class, 'row'], $paginator->items()),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /** @return array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool}|null */
    private static function member(?Member $member): ?array
    {
        return $member === null ? null : self::memberRef($member);
    }

    /** @return array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool} */
    public static function memberRef(Member $member): array
    {
        return MemberRefSerializer::ref($member);
    }
}
