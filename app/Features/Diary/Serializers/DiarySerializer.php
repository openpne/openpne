<?php

namespace App\Features\Diary\Serializers;

use App\Features\Member\Serializers\MemberRefSerializer;
use App\LinkCard\LinkCardSerializer;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\DiaryCommentImage;
use App\Models\DiaryImage;
use App\Models\Member;
use App\Support\BodyFormat;
use App\Support\BodyRenderer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Modern surface shapes for Diary feature. visibility is always a string slug
 * (never raw int) to avoid JS falsy-zero bugs with Open=0.
 */
class DiarySerializer
{
    /**
     * @return array{id: int, title: string, excerpt: string, visibility: string, commentCount: int, hasImages: bool, thumbnails: list<string>, author: array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool}, createdAt: string}
     */
    public static function summary(Diary $diary): array
    {
        return [
            'id' => $diary->getKey(),
            'title' => $diary->title,
            'excerpt' => BodyRenderer::excerpt($diary->body, $diary->format),
            'visibility' => $diary->visibility->slug(),
            // List/feed callers eager-load the counts; a single route-bound diary lazy-loads them
            // here so the values are never silently zero.
            'commentCount' => $diary->comments_count ?? $diary->loadCount('comments')->comments_count,
            // The feed shows only a has-photos marker, so the summary carries the boolean,
            // not the images themselves.
            'hasImages' => ($diary->images_count ?? $diary->loadCount('images')->images_count) > 0,
            // Rich rows eager-load images.file; a caller that didn't (dashboard digests) leaves the
            // relation unloaded, and we return [] rather than firing a query per row — the nested
            // file guard keeps that true even for an images-without-file load. A row whose File is
            // gone drops out of the list (the join cascades with it).
            'thumbnails' => $diary->relationLoaded('images')
                ? $diary->images
                    ->map(fn (DiaryImage $image): ?string => $image->relationLoaded('file')
                        ? $image->file?->thumbnailUrl(120, 120, square: true)
                        : null)
                    ->filter()->values()->all()
                : [],
            'author' => MemberRefSerializer::ref($diary->member),
            'createdAt' => $diary->created_at->toIso8601String(),
        ];
    }

    /**
     * detail is a superset of summary (the React DiaryDetail extends DiarySummary): it carries the
     * full images plus hasImages, so a caller typed on either shape reads consistent data.
     *
     * @return array{id: int, title: string, excerpt: string, body: string, format: string, bodyHtml: string|null, visibility: string, commentCount: int, hasImages: bool, thumbnails: list<string>, images: list<array{id: int, url: string, thumbnailUrl: string, fitSources: list<array{url: string, box: int}>, cropSources: array{tall?: list<array{url: string, width: int}>, wide?: list<array{url: string, width: int}>}, width: int|null, height: int|null}>, author: array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool}, linkCard: array{url: string, title: string, description: string|null, siteName: string|null, domain: string, layout: string, imageUrl: string|null, imageWidth: int|null, imageHeight: int|null, fitSources: list<array{url: string, box: int}>}|null, createdAt: string}
     */
    public static function detail(Diary $diary, ?Member $viewer): array
    {
        $images = $diary->images->map([self::class, 'image'])->all();

        return [
            'id' => $diary->getKey(),
            'title' => $diary->title,
            'excerpt' => BodyRenderer::excerpt($diary->body, $diary->format),
            'body' => $diary->body,
            // bodyHtml is the server-rendered decoration HTML for a non-plain body, null for plain (the
            // client then renders body itself via the plain path); it is the only trusted-HTML prop.
            'format' => $diary->format->value,
            'bodyHtml' => $diary->format === BodyFormat::Plain ? null : BodyRenderer::render($diary->body, $diary->format)->toHtml(),
            'visibility' => $diary->visibility->slug(),
            'commentCount' => $diary->comments_count ?? $diary->loadCount('comments')->comments_count,
            'hasImages' => $images !== [],
            // The list-row thumbnails (number-ordered) reuse the already-loaded images, keeping the
            // DiaryDetail-extends-DiarySummary TS shape; file-less rows carry an empty url and drop out.
            'thumbnails' => array_values(array_filter(array_column($images, 'thumbnailUrl'))),
            'images' => $images,
            'author' => MemberRefSerializer::ref($diary->member),
            'linkCard' => LinkCardSerializer::card($diary, $viewer),
            'createdAt' => $diary->created_at->toIso8601String(),
        ];
    }

    /**
     * The older/newer pager needs only identity, title, and date; null-transparent so a caller can
     * forward a missing neighbour straight through. createdAt matches detail()/summary().
     *
     * @return array{id: int, title: string, createdAt: string}|null
     */
    public static function neighbor(?Diary $diary): ?array
    {
        if ($diary === null) {
            return null;
        }

        return [
            'id' => $diary->getKey(),
            'title' => $diary->title,
            'createdAt' => $diary->created_at->toIso8601String(),
        ];
    }

    /**
     * A single attached image (diary or comment): the full-bytes url plus the thumbnail sources a
     * surface picks from, all FilePolicy-gated. See docs/internals/images.md for which one a
     * surface takes and why the intrinsic size travels with them. Tolerates a row whose File is
     * gone (defensive; the join cascades with it).
     *
     * @return array{id: int, url: string, thumbnailUrl: string, fitSources: list<array{url: string, box: int}>, cropSources: array{tall?: list<array{url: string, width: int}>, wide?: list<array{url: string, width: int}>}, width: int|null, height: int|null}
     */
    public static function image(DiaryImage|DiaryCommentImage $image): array
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
     * `author` is null for a withdrawn member; `deletable` is the viewer-specific delete
     * permission, computed server-side so the client never re-derives authorization.
     *
     * @return array{id: int, number: int, body: string, images: list<array{id: int, url: string, thumbnailUrl: string, fitSources: list<array{url: string, box: int}>, cropSources: array{tall?: list<array{url: string, width: int}>, wide?: list<array{url: string, width: int}>}, width: int|null, height: int|null}>, linkCard: array{url: string, title: string, description: string|null, siteName: string|null, domain: string, layout: string, imageUrl: string|null, imageWidth: int|null, imageHeight: int|null, fitSources: list<array{url: string, box: int}>}|null, author: array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool}|null, createdAt: string, deletable: bool}
     */
    public static function comment(DiaryComment $comment, ?Member $viewer): array
    {
        return [
            'id' => $comment->getKey(),
            'number' => $comment->number,
            'body' => $comment->body,
            'images' => $comment->images->map([self::class, 'image'])->all(),
            'linkCard' => LinkCardSerializer::card($comment, $viewer),
            'author' => $comment->member ? MemberRefSerializer::ref($comment->member) : null,
            'createdAt' => $comment->created_at->toIso8601String(),
            'deletable' => $comment->isDeletableBy($viewer),
        ];
    }

    /**
     * @param  Collection<int, DiaryComment>  $comments
     * @return list<array>
     */
    public static function comments(Collection $comments, ?Member $viewer): array
    {
        return $comments->map(fn (DiaryComment $comment): array => self::comment($comment, $viewer))->all();
    }

    /**
     * @param  LengthAwarePaginator<int, Diary>  $paginator
     * @return array{data: list<array>, meta: array{currentPage: int, lastPage: int, perPage: int, total: int}}
     */
    public static function paginator(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map([self::class, 'summary'], $paginator->items()),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }
}
