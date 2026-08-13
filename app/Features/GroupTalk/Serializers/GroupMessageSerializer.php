<?php

namespace App\Features\GroupTalk\Serializers;

use App\Features\GroupTalk\GroupTalkCursor;
use App\Features\GroupTalk\GroupTalkPage;
use App\Features\GroupTalk\GroupTalkPermissions;
use App\Models\GroupMessage;
use App\Models\GroupMessageImage;
use App\Models\GroupMessageMention;
use App\Models\Member;

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
     * @return array{id: int, body: string, createdAt: string, cursor: string, author: array{id: int, name: string, imageUrl: string|null, avatarColor: string|null}|null, mentions: list<array{memberId: int, offset: int, length: int}>, images: list<array{id: int, url: string, thumbnailUrl: string}>, isOwn: bool, canDelete: bool}
     */
    public static function message(GroupMessage $message, GroupTalkPermissions $permissions): array
    {
        return [
            'id' => $message->getKey(),
            'body' => $message->body,
            'createdAt' => $message->created_at->toIso8601String(),
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
     * A page of the conversation, oldest first, and whether anything remains further back.
     *
     * @return array{messages: list<array>, hasOlder: bool}
     */
    public static function page(GroupTalkPage $page, GroupTalkPermissions $permissions): array
    {
        return [
            'messages' => $page->messages
                ->map(fn (GroupMessage $message): array => self::message($message, $permissions))
                ->values()
                ->all(),
            'hasOlder' => $page->hasOlder,
        ];
    }

    /**
     * A single attached image: the full-bytes url and a square thumbnail, both FilePolicy-gated
     * behind the talk's read access. Tolerates a row whose File is gone (defensive; the join
     * cascades with it).
     *
     * @return array{id: int, url: string, thumbnailUrl: string}
     */
    public static function image(GroupMessageImage $image): array
    {
        $file = $image->file;

        return [
            'id' => $image->getKey(),
            'url' => $file?->url() ?? '',
            'thumbnailUrl' => $file?->thumbnailUrl(120, 120, square: true) ?? '',
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
