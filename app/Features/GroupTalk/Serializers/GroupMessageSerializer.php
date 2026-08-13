<?php

namespace App\Features\GroupTalk\Serializers;

use App\Features\GroupTalk\GroupTalkCursor;
use App\Features\GroupTalk\GroupTalkPage;
use App\Features\GroupTalk\GroupTalkPermissions;
use App\Models\GroupMessage;
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
     * @return array{id: int, body: string, createdAt: string, cursor: string, author: array{id: int, name: string, imageUrl: string|null, avatarColor: string|null}|null, isOwn: bool, canDelete: bool}
     */
    public static function message(GroupMessage $message, GroupTalkPermissions $permissions): array
    {
        return [
            'id' => $message->getKey(),
            'body' => $message->body,
            'createdAt' => $message->created_at->toIso8601String(),
            'cursor' => (string) GroupTalkCursor::of($message),
            'author' => self::author($message->author),
            'isOwn' => $permissions->owns($message),
            'canDelete' => $permissions->canDelete($message),
        ];
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
