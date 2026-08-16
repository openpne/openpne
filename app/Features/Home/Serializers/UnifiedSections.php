<?php

namespace App\Features\Home\Serializers;

use App\Features\Diary\Serializers\DiarySerializer;
use App\Features\Timeline\Serializers\TimelinePostSerializer;
use App\Models\Diary;
use App\Models\Group;
use App\Models\Member;
use App\Models\TimelinePost;
use Illuminate\Support\Collection;

/**
 * The projections the unified layout's pages share (SnsSettingKey::ModernUnifiedHome): the picture
 * mosaic, the group cover cards, and the row of faces. The same React components read all three, so
 * the shape is a contract between the pages rather than a detail of either — the home draws them
 * about the viewer, the member page about whoever it is about, and the two must not drift.
 *
 * Shaping only: every caller has already resolved, under its own clearance, which rows to pass in.
 */
final class UnifiedSections
{
    /**
     * Photos in the grid, and therefore the most parents worth reading per source: one picture each
     * already fills it. 2 leads + 6 squares — the mosaic's rows come out even at this count.
     */
    public const PHOTOS = 8;

    /**
     * The picture grid: every attachment on the given content, newest parent first, capped. Each tile
     * carries the content it came from so it opens there — a picture on these pages is a way back into
     * what it was posted with, not a gallery entry of its own.
     *
     * @param  Collection<int, Diary>  $diaries  images.file eager-loaded by the caller
     * @param  Collection<int, TimelinePost>  $posts
     * @return list<array{source: 'diary'|'timeline', href: string, image: array}>
     */
    public static function photos(Collection $diaries, Collection $posts): array
    {
        return self::photosFromParents([
            ...$diaries->map(fn (Diary $diary): array => [
                'source' => 'diary',
                'at' => $diary->created_at,
                'id' => $diary->getKey(),
                'href' => "/diary/{$diary->getKey()}",
                'images' => $diary->images->map([DiarySerializer::class, 'image'])->all(),
            ]),
            ...$posts->map(fn (TimelinePost $post): array => [
                'source' => 'timeline',
                'at' => $post->created_at,
                'id' => $post->getKey(),
                'href' => "/timeline/{$post->getKey()}",
                'images' => $post->images->map([TimelinePostSerializer::class, 'image'])->all(),
            ]),
        ]);
    }

    /**
     * The grid's order and cap, over content of any kind: each parent is the thing a tile opens,
     * carrying the pictures posted with it. Ordered by parent (created_at, then id, then source)
     * before the pictures inside one are laid out in the order their author arranged them, so the cap
     * always cuts the same tiles. Ids come from as many tables as there are sources, so they order a
     * source apart from itself rather than against another; the source name settles the rest.
     *
     * `images` is walked lazily and only as far as the cap reaches, so a caller whose shaping costs
     * something per picture — a per-file authorization check — pays it for the tiles that are shown
     * and not for the ones the cap cuts. A picture the caller omits leaves no trace of itself.
     *
     * @param  list<array{source: string, at: \DateTimeInterface, id: int, href: string, images: iterable<array>}>  $parents
     * @return list<array{source: string, href: string, image: array}>
     */
    public static function photosFromParents(array $parents): array
    {
        usort($parents, fn (array $a, array $b): int => $b['at'] <=> $a['at']
            ?: $b['id'] <=> $a['id']
            ?: $a['source'] <=> $b['source']);

        $photos = [];
        foreach ($parents as $parent) {
            foreach ($parent['images'] as $image) {
                $photos[] = ['source' => $parent['source'], 'href' => $parent['href'], 'image' => $image];

                if (count($photos) === self::PHOTOS) {
                    return $photos;
                }
            }
        }

        return $photos;
    }

    /**
     * The joined-group cards. Their own tile markup lays the name over the cover, so the digest grid's
     * avatarColor/isAi keys (which a group never carries anything in) are left out.
     *
     * @param  Collection<int, Group>  $groups  `image` eager-loaded by ListMemberGroups
     * @return list<array{id: int, name: string, imageUrl: string|null, href: string}>
     */
    public static function groups(Collection $groups): array
    {
        return $groups->map(fn (Group $group): array => [
            'id' => $group->getKey(),
            'name' => $group->name,
            'imageUrl' => $group->image?->thumbnailUrl(320, 320, square: true),
            'href' => "/groups/{$group->getKey()}",
        ])->values()->all();
    }

    /**
     * The faces row — the digest's friend shape, which is what the shared tile idioms consume. Named
     * for what it draws rather than for the relationship: the same row shows a member's friends on
     * one page and a group's members on another, and the tiles must not tell them apart.
     *
     * @param  Collection<int, Member>  $people  `avatar.file` eager-loaded by the caller's query
     * @return list<array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool, href: string}>
     */
    public static function people(Collection $people): array
    {
        return $people->map(fn (Member $person): array => [
            'id' => $person->getKey(),
            'name' => $person->name,
            'imageUrl' => $person->avatar?->file?->thumbnailUrl(320, 320, square: true),
            'avatarColor' => $person->avatar_color?->hex(),
            'isAi' => $person->isAiAccount(),
            'href' => "/member/{$person->getKey()}",
        ])->values()->all();
    }
}
