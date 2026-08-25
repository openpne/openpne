<?php

namespace App\Features\Timeline\Serializers;

use App\Features\Member\Serializers\MemberRefSerializer;
use App\LinkCard\LinkCardSerializer;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Models\TimelinePostImage;
use App\Models\TimelinePostMention;
use App\Models\TimelinePostTag;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Modern surface shapes for the Timeline feature. visibility is always a string slug (never raw
 * int) to avoid JS falsy-zero bugs with Open=0. A timeline card shows the body and image inline,
 * so entry() carries the full content.
 */
class TimelinePostSerializer
{
    /**
     * @return array{id: int, body: string, mentions: list<array{memberId: int, offset: int, length: int}>, tags: list<array{tag: string, offset: int, length: int}>, visibility: string, hasImages: bool, replyCount: int, images: list<array{id: int, url: string, thumbnailUrl: string, fitSources: list<array{url: string, box: int}>, cropSources: array{tall?: list<array{url: string, width: int}>, wide?: list<array{url: string, width: int}>}, width: int|null, height: int|null}>, author: array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool}, linkCard: array{url: string, title: string, description: string|null, siteName: string|null, domain: string, layout: string, imageUrl: string|null, imageWidth: int|null, imageHeight: int|null, fitSources: list<array{url: string, box: int}>}|null, createdAt: string}
     */
    public static function entry(TimelinePost $post, ?Member $viewer): array
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
            // The #hashtag ranges, in body order; the client links each to its tag page. The tag
            // travels normalized (the body's own text is inside the range), because that is what the
            // page is addressed by. linkableTags() is the one seam deciding what links.
            'tags' => $post->linkableTags()->map(fn (TimelinePostTag $tag): array => [
                'tag' => $tag->tag,
                'offset' => $tag->offset,
                'length' => $tag->length,
            ])->all(),
            'visibility' => $post->visibility->slug(),
            'hasImages' => $images !== [],
            // Top-level list queries eager-load withCount('replies'); a reply (never shown with a
            // count — the thread is flat) or a single route-bound post reports 0 rather than firing
            // a per-row loadCount.
            'replyCount' => $post->replies_count ?? 0,
            'images' => $images,
            'author' => MemberRefSerializer::ref($post->member),
            'linkCard' => LinkCardSerializer::card($post, $viewer),
            'createdAt' => $post->created_at->toIso8601String(),
        ];
    }

    /**
     * A single attached image: the full-bytes url plus the thumbnail sources a surface picks from,
     * all FilePolicy-gated. See docs/internals/images.md for which one a surface takes and why the
     * intrinsic size travels with them. Tolerates a row whose File is gone (defensive; the join
     * cascades with it).
     *
     * @return array{id: int, url: string, thumbnailUrl: string, fitSources: list<array{url: string, box: int}>, cropSources: array{tall?: list<array{url: string, width: int}>, wide?: list<array{url: string, width: int}>}, width: int|null, height: int|null}
     */
    public static function image(TimelinePostImage $image): array
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

    /**
     * @param  LengthAwarePaginator<int, TimelinePost>  $paginator
     * @return array{data: list<array>, meta: array{currentPage: int, lastPage: int, perPage: int, total: int}}
     */
    public static function paginator(LengthAwarePaginator $paginator, ?Member $viewer): array
    {
        return [
            'data' => array_map(fn (TimelinePost $post): array => self::entry($post, $viewer), $paginator->items()),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }
}
