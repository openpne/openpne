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
     * @param  list<array{emoji: string, count: int, mine: bool}>  $reactions  the message's chip row, as
     *                                                                         MessageReactionAggregates counts it. Passed in rather than read off the model: the rows
     *                                                                         behind a chip are one per reactor, and no page hydrates them.
     * @param  array<int, GroupMessage>  $parents  the answered messages of the whole page, as
     *                                             ReplyReferences read them. Required rather than defaulted: a caller that
     *                                             forgot it would draw every reply as one whose parent was deleted
     *
     * The reader is taken off $permissions rather than passed alongside it — a card of one of this
     * site's own pages is built against whoever is asking, and `$permissions->member` is already
     * that person. Passing them twice would let the two disagree.
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
     * The message this one answers: null when it answers nothing, `{deleted: true}` when the id names
     * a row that is not there. Only one level travels — a parent that is itself a reply describes its
     * own text here, never its own parent.
     *
     * Everything shown is read off the parent now rather than copied at write time, so an answer
     * cannot go on quoting something that was retracted. `excerpt` is the same line every list in the
     * app previews a message by ({@see ChatPreview}) — a payload bound, with the visible truncation
     * left to the client's clip.
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

    /** @return array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool}|null */
    private static function author(?Member $member): ?array
    {
        return $member === null ? null : MemberRefSerializer::ref($member);
    }
}
