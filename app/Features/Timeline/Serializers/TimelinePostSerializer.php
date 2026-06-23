<?php

namespace App\Features\Timeline\Serializers;

use App\Models\TimelinePost;
use App\Models\TimelinePostImage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Modern surface shapes for the Timeline feature. visibility is always a string slug (never raw
 * int) to avoid JS falsy-zero bugs with Open=0. A timeline card shows the body and image inline,
 * so entry() carries the full content.
 */
class TimelinePostSerializer
{
    /**
     * @return array{id: int, body: string, visibility: string, hasImages: bool, images: list<array{id: int, url: string, thumbnailUrl: string}>, author: array{id: int, name: string}, createdAt: string}
     */
    public static function entry(TimelinePost $post): array
    {
        $images = $post->images->map([self::class, 'image'])->all();

        return [
            'id' => $post->getKey(),
            'body' => $post->body,
            'visibility' => $post->visibility->slug(),
            'hasImages' => $images !== [],
            'images' => $images,
            'author' => [
                'id' => $post->member->getKey(),
                'name' => $post->member->name,
            ],
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
