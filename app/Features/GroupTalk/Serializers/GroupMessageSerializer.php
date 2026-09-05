<?php

namespace App\Features\GroupTalk\Serializers;

use App\Features\GroupTalk\GroupTalkCursor;
use App\Features\GroupTalk\GroupTalkPage;
use App\Features\GroupTalk\GroupTalkPermissions;
use App\Features\Member\Serializers\MemberRefSerializer;
use App\LinkCard\LinkCardSerializer;
use App\Models\GroupMessage;
use App\Models\GroupMessageImage;
use App\Models\GroupMessageMention;
use App\Models\Member;
use App\Support\ChatPreview;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Every message carries its own `cursor`, so the tuple encoding stays on this side and a client never
 * assembles one out of a timestamp and an id.
 */
class GroupMessageSerializer
{
    /**
     * @param  list<array{emoji: string, count: int, mine: bool}>  $reactions  the message's chip row,
     *                                                                         passed in rather than read off the model, which no page hydrates
     * @param  array<int, GroupMessage>  $parents  the answered messages of the whole page; required
     *                                             rather than defaulted, since a caller that omitted it would draw every
     *                                             reply as one whose parent was deleted
     * @return array{id: int, body: string, createdAt: string, cursor: string, author: array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool}|null, mentions: list<array{memberId: int, offset: int, length: int}>, images: list<array{id: int, url: string, thumbnailUrl: string, fitSources: list<array{url: string, box: int}>, cropSources: array{tall?: list<array{url: string, width: int}>, wide?: list<array{url: string, width: int}>}, width: int|null, height: int|null}>, linkCard: array{url: string, title: string, description: string|null, siteName: string|null, domain: string, layout: string, imageUrl: string|null, imageWidth: int|null, imageHeight: int|null, fitSources: list<array{url: string, box: int}>}|null, reactions: list<array{emoji: string, count: int, mine: bool}>, inReplyTo: array{deleted: bool, id?: int, cursor?: string, author?: array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool}|null, excerpt?: string, thumbnailUrl?: string|null}|null, isOwn: bool, canDelete: bool}
     */
    public static function message(GroupMessage $message, GroupTalkPermissions $permissions, array $reactions, array $parents): array
    {
        return [
            'id' => $message->getKey(),
            'body' => $message->body,
            'createdAt' => self::instant($message->created_at),
            'cursor' => (string) GroupTalkCursor::of($message),
            'author' => self::author($message->author),
            'mentions' => self::mentions($message),
            'images' => $message->images->map([self::class, 'image'])->values()->all(),
            'linkCard' => LinkCardSerializer::card($message, $permissions->member),
            'reactions' => $reactions,
            'inReplyTo' => self::inReplyTo($message, $parents),
            'isOwn' => $permissions->owns($message),
            'canDelete' => $permissions->canDelete($message),
        ];
    }

    /**
     * Only one level travels: a parent that is itself a reply describes its own text here, never its
     * own parent. `excerpt` is a payload bound ({@see ChatPreview}), with the visible truncation left
     * to the client's clip.
     *
     * @param  array<int, GroupMessage>  $parents
     * @return array{deleted: bool, id?: int, cursor?: string, author?: array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool}|null, excerpt?: string, thumbnailUrl?: string|null}|null
     */
    private static function inReplyTo(GroupMessage $message, array $parents): ?array
    {
        if ($message->in_reply_to_id === null) {
            return null;
        }

        $parent = $parents[(int) $message->in_reply_to_id] ?? null;

        if ($parent === null) {
            return ['deleted' => true];
        }

        return [
            'deleted' => false,
            'id' => $parent->getKey(),
            'cursor' => (string) GroupTalkCursor::of($parent),
            'author' => self::author($parent->author),
            'excerpt' => ChatPreview::lineOrImages([$parent->body], $parent->images->isNotEmpty()),
            'thumbnailUrl' => $parent->images->first()?->file?->thumbnailUrl(120, 120, square: true),
        ];
    }

    /**
     * Ascending and non-overlapping, in the relation's own offset order. No display name travels with
     * a range: the body already carries it, frozen at post time.
     *
     * @return list<array{memberId: int, offset: int, length: int}>
     */
    public static function mentions(GroupMessage $message): array
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
     * @param  array<int, list<array{emoji: string, count: int, mine: bool}>>  $reactions  chip rows by message id;
     *                                                                                     a message nobody reacted to has no key
     * @param  array<int, GroupMessage>  $parents  the answered messages, by id
     * @return array{messages: list<array>, hasOlder: bool, hasNewer: bool}
     */
    public static function page(GroupTalkPage $page, GroupTalkPermissions $permissions, array $reactions, array $parents): array
    {
        return [
            'messages' => $page->messages
                ->map(fn (GroupMessage $message): array => self::message($message, $permissions, $reactions[$message->getKey()] ?? [], $parents))
                ->values()
                ->all(),
            'hasOlder' => $page->hasOlder,
            'hasNewer' => $page->hasNewer,
        ];
    }

    /**
     * `cursor` is the same position already encoded, so the client echoes it back rather than
     * assembling one.
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
     * Tolerates a row whose File is gone, though the join cascades with it. Every surface picks from
     * the same two ladders (docs/internals/images.md, "The two ladders").
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

    /** @return array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool}|null */
    private static function author(?Member $member): ?array
    {
        return $member === null ? null : MemberRefSerializer::ref($member);
    }
}
