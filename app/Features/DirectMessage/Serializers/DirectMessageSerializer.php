<?php

namespace App\Features\DirectMessage\Serializers;

use App\Features\DirectMessage\DirectMessageListItem;
use App\Models\DirectMessage;
use App\Models\DirectMessageFile;
use App\Models\Member;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Modern surface shapes for what the mailbox still owns under chat: the drafts box and the draft
 * form. counterparty/recipient is null for a withdrawn member (a deleted account leaves the row),
 * and datetimes are ISO strings the client formats.
 */
class DirectMessageSerializer
{
    /**
     * A box-list row (DirectMessageListItem): the counterparty (From for the inbox, To otherwise), the
     * subject, the box-appropriate date, and unread (only ever true in the inbox).
     *
     * @return array{id: int, counterparty: array{id: int, name: string, imageUrl: string|null, avatarColor: string|null}|null, subject: string, date: string, unread: bool}
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
     * The draft edit-form shape: the editable text, the fixed recipient (null if withdrawn), and the
     * current images (each removable by id). Callers eager-load files.file and draftRecipient.
     *
     * @return array{id: int, subject: string, body: string, recipient: array{id: int, name: string, imageUrl: string|null, avatarColor: string|null}|null, images: list<array{id: int, url: string, thumbnailUrl: string}>}
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
     * A single attached image: the full-bytes url and a square thumbnail, both FilePolicy-gated.
     * Tolerates a row whose File is gone (defensive; the join cascades with it).
     *
     * @return array{id: int, url: string, thumbnailUrl: string}
     */
    public static function image(DirectMessageFile $image): array
    {
        $file = $image->file;

        return [
            'id' => $image->getKey(),
            'url' => $file?->url() ?? '',
            'thumbnailUrl' => $file?->thumbnailUrl(120, 120, square: true) ?? '',
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

    /** @return array{id: int, name: string, imageUrl: string|null, avatarColor: string|null}|null */
    private static function member(?Member $member): ?array
    {
        return $member === null ? null : self::memberRef($member);
    }

    /** A present member (e.g. a compose recipient), always non-null. @return array{id: int, name: string, imageUrl: string|null, avatarColor: string|null} */
    public static function memberRef(Member $member): array
    {
        return [
            'id' => $member->getKey(),
            'name' => $member->name,
            'imageUrl' => $member->avatar?->file?->thumbnailUrl(120, 120, square: true),
            'avatarColor' => $member->avatar_color?->hex(),
        ];
    }
}
