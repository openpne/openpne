<?php

namespace App\Features\Message\Serializers;

use App\Features\Message\MessageListItem;
use App\Features\Message\MessageView;
use App\Models\Member;
use App\Models\Message;
use App\Models\MessageFile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Modern surface shapes for private messages. counterparty/sender is null for a withdrawn member (a
 * deleted account leaves the row). Datetimes are ISO strings (the client formats them). Which box a
 * page shows, and the per-box show/action routes, are the controller's concern — the client builds
 * those URLs from the box slug.
 */
class MessageSerializer
{
    /**
     * A box-list row (MessageListItem): the counterparty (From for the inbox, To otherwise), the
     * subject, the box-appropriate date, and unread (only ever true in the inbox).
     *
     * @return array{id: int, counterparty: array{id: int, name: string, imageUrl: string|null}|null, subject: string, date: string, unread: bool}
     */
    public static function row(MessageListItem $item): array
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
     * The message show shape: the body and images plus the counterparties (To when the viewer sent
     * it, the single From otherwise), and the adjacent-message ids for the in-box pager.
     *
     * @return array{id: int, subject: string, body: string, createdAt: string, images: list<array{id: int, url: string, thumbnailUrl: string}>, counterparties: list<array{id: int, name: string, imageUrl: string|null}>, viewerIsSender: bool, box: string, previousId: int|null, nextId: int|null}
     */
    public static function view(MessageView $view): array
    {
        $message = $view->message;

        return [
            'id' => $message->getKey(),
            'subject' => (string) $message->subject,
            'body' => (string) $message->body,
            'createdAt' => $message->created_at->toIso8601String(),
            'images' => $message->files->map([self::class, 'image'])->all(),
            'counterparties' => array_values(array_filter(array_map([self::class, 'member'], $view->counterparties))),
            'viewerIsSender' => $view->viewerIsSender,
            'box' => $view->box->value,
            'previousId' => $view->previousId,
            'nextId' => $view->nextId,
        ];
    }

    /**
     * The draft edit-form shape: the editable text, the fixed recipient (null if withdrawn), and the
     * current images (each removable by id). Callers eager-load files.file and draftRecipient.
     *
     * @return array{id: int, subject: string, body: string, recipient: array{id: int, name: string, imageUrl: string|null}|null, images: list<array{id: int, url: string, thumbnailUrl: string}>}
     */
    public static function draftForm(Message $draft): array
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
    public static function image(MessageFile $image): array
    {
        $file = $image->file;

        return [
            'id' => $image->getKey(),
            'url' => $file?->url() ?? '',
            'thumbnailUrl' => $file?->thumbnailUrl(120, 120, square: true) ?? '',
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, MessageListItem>  $paginator
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

    /** @return array{id: int, name: string, imageUrl: string|null}|null */
    private static function member(?Member $member): ?array
    {
        return $member === null ? null : self::memberRef($member);
    }

    /** A present member (e.g. a compose recipient), always non-null. @return array{id: int, name: string, imageUrl: string|null} */
    public static function memberRef(Member $member): array
    {
        return [
            'id' => $member->getKey(),
            'name' => $member->name,
            'imageUrl' => $member->avatar?->file?->thumbnailUrl(76, 76, square: true),
        ];
    }
}
