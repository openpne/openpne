<?php

namespace App\Features\Timeline\Serializers;

use App\LinkCard\LinkCardSerializer;
use App\Models\TimelinePost;
use App\Models\TimelinePostImage;
use App\Models\TimelinePostMention;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Modern surface shapes for the Timeline feature. visibility is always a string slug (never raw
 * int) to avoid JS falsy-zero bugs with Open=0. A timeline card shows the body and image inline,
 * so entry() carries the full content.
 */
class TimelinePostSerializer
{
    /**
     * @return array{id: int, body: string, mentions: list<array{memberId: int, offset: int, length: int}>, visibility: string, hasImages: bool, replyCount: int, images: list<array{id: int, url: string, thumbnailUrl: string}>, author: array{id: int, name: string, imageUrl: string|null, avatarColor: string|null}, linkCard: array{url: string, title: string, description: string|null, siteName: string|null, domain: string, imageUrl: string|null}|null, createdAt: string}
     */
    public static function entry(TimelinePost $post): array
    {
        $images = $post->images->map([self::class, 'image'])->all();

        return [
            'id' => $post->getKey(),
            'body' => $post->body,
            // The @mention ranges over the body, in body order; the client links them (entity-text.tsx).
            // No display name travels with them — the body already carries it, frozen at post time.
            'mentions' => $post->mentions->map(fn (TimelinePostMention $mention): array => [
                'memberId' => $mention->member_id,
                'offset' => $mention->offset,
                'length' => $mention->length,
            ])->all(),
            'visibility' => $post->visibility->slug(),
            'hasImages' => $images !== [],
            // Top-level list queries eager-load withCount('replies'); a reply (never shown with a
            // count — the thread is flat) or a single route-bound post reports 0 rather than firing
            // a per-row loadCount.
            'replyCount' => $post->replies_count ?? 0,
            'images' => $images,
            'author' => [
                'id' => $post->member->getKey(),
                'name' => $post->member->name,
                'imageUrl' => $post->member->avatar?->file?->thumbnailUrl(76, 76, square: true),
                'avatarColor' => $post->member->avatar_color?->hex(),
            ],
            'linkCard' => LinkCardSerializer::card($post),
            'createdAt' => $post->created_at->toIso8601String(),
        ];
    }

    /**
     * A single attached image: the full-bytes url and a square thumbnail, both FilePolicy-gated.
     * Tolerates a row whose File is gone (defensive; the join cascades with it).
     *
     * @return array{id: int, url: string, thumbnailUrl: string}
     */
    public static function image(TimelinePostImage $image): array
    {
        $file = $image->file;

        return [
            'id' => $image->getKey(),
            'url' => $file?->url() ?? '',
            'thumbnailUrl' => $file?->thumbnailUrl(120, 120, square: true) ?? '',
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, TimelinePost>  $paginator
     * @return array{data: list<array>, meta: array{currentPage: int, lastPage: int, perPage: int, total: int}}
     */
    public static function paginator(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map([self::class, 'entry'], $paginator->items()),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }
}
