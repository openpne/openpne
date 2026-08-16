<?php

namespace App\Features\Home\Serializers;

use App\Features\Diary\Queries\RecentMemberDiaries;
use App\Features\Diary\Serializers\DiarySerializer;
use App\Features\Friend\Queries\ListFriends;
use App\Features\Group\Queries\ListMemberGroups;
use App\Features\Profile\Queries\ShowProfile;
use App\Features\Profile\Serializers\ProfileSerializer;
use App\Features\Timeline\Queries\MemberTimeline;
use App\Features\Timeline\Serializers\TimelinePostSerializer;
use App\Models\Diary;
use App\Models\Group;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Feature;
use Illuminate\Support\Collection;

/**
 * The unified Modern home (SnsSettingKey::ModernUnifiedHome): the viewer's own profile, the groups and
 * people they belong among, their latest content, and the sections they can act in — the last of which
 * the client builds from the chrome registry, so nothing about it travels here.
 *
 * It gathers as well as shapes, unlike its digest sibling, because the whole page is about one member
 * and that member is the viewer: every query takes the viewer as both viewer and owner, so there is no
 * parameter through which someone else's page could be assembled. Every source is an existing
 * viewer-scoped query, so this adds no way to read anything the profile and archive screens do not
 * already show.
 */
class UnifiedHomeSerializer
{
    /**
     * Photos in the grid, and therefore the most parents worth reading per source: one picture each
     * already fills it. 2 leads + 6 squares — the mosaic's rows come out even at this count.
     */
    private const PHOTOS = 8;

    /** Rows in the "my recent %diaries%" list — a lead-in to the archive, not the archive. */
    private const DIARY_ROWS = 3;

    /** Group cards: two full rows of three. */
    private const GROUPS = 6;

    /** Faces in the people row. */
    private const FRIENDS = 10;

    /**
     * @return array{profile: array{id: int, name: string, avatarUrl: string|null, avatarUrlLarge: string|null, avatarColor: string|null, isAi: bool, bio: string|null}, groups: list<array{id: int, name: string, imageUrl: string|null, href: string}>, friends: list<array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool, href: string}>, recentPhotos: list<array{source: 'diary'|'timeline', href: string, image: array}>, recentDiaries: list<array>}
     */
    public static function page(Member $viewer): array
    {
        // The same rows feed both halves of the "latest" section: the newest few as list rows, all of
        // them as picture sources.
        $diaries = Feature::Diary->enabled()
            ? (new RecentMemberDiaries)($viewer, $viewer, self::PHOTOS)->load('images.file')
            : collect();

        return [
            'profile' => self::profile($viewer),
            // Viewer as both viewer and owner: their own memberships and their own friends, at the
            // clearance the profile screen already grants them over themselves.
            'groups' => self::groups(Feature::Group->enabled()
                ? (new ListMemberGroups)->take($viewer, self::GROUPS)
                : collect()),
            'friends' => self::friends(Feature::Friend->enabled()
                ? (new ListFriends)->take($viewer, $viewer, self::FRIENDS)
                : collect()),
            'recentPhotos' => self::photos($diaries, Feature::Timeline->enabled()
                ? (new MemberTimeline)->take($viewer, $viewer, self::PHOTOS)
                : collect()),
            'recentDiaries' => $diaries->take(self::DIARY_ROWS)->map([DiarySerializer::class, 'summary'])->values()->all(),
        ];
    }

    /**
     * The identity block. Viewer and owner are the same member, so the profile fields resolve at full
     * clearance.
     *
     * The two avatar sizes are the hero's `srcset` rungs: it is painted as a wide crop across the whole
     * content column, so the 180px the profile screen's round face uses would not survive the scale.
     *
     * @return array{id: int, name: string, avatarUrl: string|null, avatarUrlLarge: string|null, avatarColor: string|null, isAi: bool, bio: string|null}
     */
    private static function profile(Member $viewer): array
    {
        $lang = app()->getLocale() === 'ja' ? 'ja_JP' : 'en';
        $fields = (new ShowProfile)($viewer, $viewer, $lang) ?? collect();
        $file = $viewer->avatar?->file;

        return [
            'id' => $viewer->getKey(),
            'name' => $viewer->name,
            // Keyed avatarUrl rather than imageUrl, as ProfileSerializer::page is: the header paints a
            // larger crop than a MemberRefSerializer::ref byline.
            'avatarUrl' => $file?->thumbnailUrl(640, 640, square: true),
            'avatarUrlLarge' => $file?->thumbnailUrl(1200, 1200, square: true),
            'avatarColor' => $viewer->avatar_color?->hex(),
            'isAi' => $viewer->isAiAccount(),
            'bio' => ProfileSerializer::bio($fields, $lang),
        ];
    }

    /**
     * The joined-group cards. Their own tile markup lays the name over the cover, so the digest grid's
     * avatarColor/isAi keys (which a group never carries anything in) are left out.
     *
     * @param  Collection<int, Group>  $groups  `image` eager-loaded by ListMemberGroups
     * @return list<array{id: int, name: string, imageUrl: string|null, href: string}>
     */
    private static function groups(Collection $groups): array
    {
        return $groups->map(fn (Group $group): array => [
            'id' => $group->getKey(),
            'name' => $group->name,
            'imageUrl' => $group->image?->thumbnailUrl(320, 320, square: true),
            'href' => "/groups/{$group->getKey()}",
        ])->values()->all();
    }

    /**
     * The faces row — the digest's friend shape, which is what the shared tile idioms consume.
     *
     * @param  Collection<int, Member>  $friends  `avatar.file` eager-loaded by ListFriends
     * @return list<array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool, href: string}>
     */
    private static function friends(Collection $friends): array
    {
        return $friends->map(fn (Member $friend): array => [
            'id' => $friend->getKey(),
            'name' => $friend->name,
            'imageUrl' => $friend->avatar?->file?->thumbnailUrl(320, 320, square: true),
            'avatarColor' => $friend->avatar_color?->hex(),
            'isAi' => $friend->isAiAccount(),
            'href' => "/member/{$friend->getKey()}",
        ])->values()->all();
    }

    /**
     * The picture grid: every attachment on the given content, newest parent first, capped. Each tile
     * carries the content it came from so it opens there — a picture on this page is a way back into
     * what it was posted with, not a gallery entry of its own.
     *
     * Ordered by parent (created_at, then id, then source) before the pictures inside one are laid
     * out in the order their author arranged them, so the cap always cuts the same tiles.
     *
     * @param  Collection<int, Diary>  $diaries  images.file eager-loaded by the caller
     * @param  Collection<int, TimelinePost>  $posts
     * @return list<array{source: 'diary'|'timeline', href: string, image: array}>
     */
    private static function photos(Collection $diaries, Collection $posts): array
    {
        $parents = [
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
        ];

        // Ids come from two tables, so they order the two sources apart rather than against each
        // other; the source name settles the remaining tie.
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
}
