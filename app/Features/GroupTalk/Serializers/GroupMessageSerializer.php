<?php

namespace App\Features\GroupTalk\Serializers;

use App\Features\GroupTalk\GroupTalkCursor;
use App\Features\GroupTalk\GroupTalkPage;
use App\Features\GroupTalk\GroupTalkPermissions;
use App\Models\GroupMessage;
use App\Models\GroupMessageImage;
use App\Models\GroupMessageMention;
use App\Models\Member;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Modern surface shapes for a group's talk. `author` is null for a withdrawn member (the FK SET
 * NULL) and the client renders the established "Withdrawn member" label. `isOwn` and `canDelete` are
 * the viewer's own answers, computed here so the client never re-derives authorization; both come
 * off one resolved GroupTalkPermissions, so serializing a page costs no query per row.
 *
 * Every message carries its `cursor` — its position in the keyset order. That is what the client
 * hands back to ask for the page before it or the messages after it, so the tuple encoding stays on
 * this side and a client never assembles one out of a timestamp and an id.
 */
class GroupMessageSerializer
{
    /**
     * @return array{id: int, body: string, createdAt: string, cursor: string, author: array{id: int, name: string, imageUrl: string|null, avatarColor: string|null}|null, mentions: list<array{memberId: int, offset: int, length: int}>, images: list<array{id: int, url: string, thumbnailUrl: string, fitSources: list<array{url: string, box: int}>, cropSources: array{tall?: list<array{url: string, width: int}>, wide?: list<array{url: string, width: int}>}, width: int|null, height: int|null}>, isOwn: bool, canDelete: bool}
     */
    public static function message(GroupMessage $message, GroupTalkPermissions $permissions): array
    {
        return [
            'id' => $message->getKey(),
            'body' => $message->body,
            'createdAt' => self::instant($message->created_at),
            'cursor' => (string) GroupTalkCursor::of($message),
            'author' => self::author($message->author),
            'mentions' => self::mentions($message),
            'images' => $message->images->map([self::class, 'image'])->values()->all(),
            'isOwn' => $permissions->owns($message),
            'canDelete' => $permissions->canDelete($message),
        ];
    }

    /**
     * The ranges EntityText splits the body on — ascending and non-overlapping, in the relation's own
     * offset order. The same wire shape the timeline ships, so one client splitter serves both; talk
     * simply has no second list, since it parses no hashtags.
     *
     * No display name travels with a range: the body already carries it, frozen at post time. A
     * member whose account is gone has no row at all (the FK cascades) and the range renders as the
     * plain text it always was — which is why no renderer needs a defensive branch.
     *
     * @return list<array{memberId: int, offset: int, length: int}>
     */
    private static function mentions(GroupMessage $message): array
    {
        return $message->mentions
            ->map(fn (GroupMessageMention $mention): array => [
                'memberId' => (int) $mention->member_id,
                'offset' => (int) $mention->offset,
                'length' => (int) $mention->length,
            ])
            ->values()
            ->all();
    }

    /**
     * A page of the conversation, oldest first, and what lies either side of it.
     *
     * @return array{messages: list<array>, hasOlder: bool, hasNewer: bool}
     */
    public static function page(GroupTalkPage $page, GroupTalkPermissions $permissions): array
    {
        return [
            'messages' => $page->messages
                ->map(fn (GroupMessage $message): array => self::message($message, $permissions))
                ->values()
                ->all(),
            'hasOlder' => $page->hasOlder,
            'hasNewer' => $page->hasNewer,
        ];
    }

    /**
     * The unread boundary the page opened on: how many messages were waiting, and the position they
     * start after, in the two shapes the client needs it in. Null for a reader with no membership
     * row, who holds no cursor at all.
     *
     * `readThrough.at` goes out through {@see instant()}, the same conversion a message's `createdAt`
     * takes, so the client can compare the two directly to find the first row past the boundary.
     * `cursor` is that same tuple as an opaque pagination position, encoded here so the client can
     * ask for the page it sits in without ever assembling a cursor of its own.
     *
     * @param  array{count: int, at: CarbonImmutable, id: int}|null  $snapshot
     * @return array{count: int, readThrough: array{at: string, id: int}, cursor: string}|null
     */
    public static function unreadSnapshot(?array $snapshot): ?array
    {
        if ($snapshot === null) {
            return null;
        }

        return [
            'count' => $snapshot['count'],
            'readThrough' => ['at' => self::instant($snapshot['at']), 'id' => $snapshot['id']],
            'cursor' => (string) new GroupTalkCursor($snapshot['at'], $snapshot['id']),
        ];
    }

    /** The one wire form of an instant in a talk, so every position the client compares is one shape. */
    public static function instant(DateTimeInterface $at): string
    {
        return CarbonImmutable::instance($at)->toIso8601String();
    }

    /**
     * A single attached image: the full-bytes url plus the thumbnail sources a surface picks from,
     * all FilePolicy-gated behind the talk's read access. See docs/internals/images.md for which
     * one a surface takes and why the intrinsic size travels with them. Tolerates a row whose File
     * is gone (defensive; the join cascades with it).
     *
     * @return array{id: int, url: string, thumbnailUrl: string, fitSources: list<array{url: string, box: int}>, cropSources: array{tall?: list<array{url: string, width: int}>, wide?: list<array{url: string, width: int}>}, width: int|null, height: int|null}
     */
    public static function image(GroupMessageImage $image): array
    {
        $file = $image->file;

        return [
            'id' => $image->getKey(),
            'url' => $file?->url() ?? '',
            'thumbnailUrl' => $file?->thumbnailUrl(120, 120, square: true) ?? '',
            'fitSources' => $file ? [
                ['url' => $file->thumbnailUrl(320, 320), 'box' => 320],
                ['url' => $file->thumbnailUrl(640, 640), 'box' => 640],
                ['url' => $file->thumbnailUrl(1200, 1200), 'box' => 1200],
            ] : [],
            'cropSources' => $file ? [
                'tall' => [
                    ['url' => $file->thumbnailUrl(300, 400, square: true), 'width' => 300],
                    ['url' => $file->thumbnailUrl(600, 800, square: true), 'width' => 600],
                ],
                'wide' => [
                    ['url' => $file->thumbnailUrl(300, 200, square: true), 'width' => 300],
                    ['url' => $file->thumbnailUrl(600, 400, square: true), 'width' => 600],
                ],
            ] : [],
            'width' => $file?->width,
            'height' => $file?->height,
        ];
    }

    /** @return array{id: int, name: string, imageUrl: string|null, avatarColor: string|null}|null */
    private static function author(?Member $member): ?array
    {
        if ($member === null) {
            return null;
        }

        return [
            'id' => $member->getKey(),
            'name' => $member->name,
            'imageUrl' => $member->avatar?->file?->thumbnailUrl(120, 120, square: true),
            'avatarColor' => $member->avatar_color?->hex(),
        ];
    }
}
