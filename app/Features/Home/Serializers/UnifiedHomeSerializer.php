<?php

namespace App\Features\Home\Serializers;

use App\Features\Diary\Queries\RecentMemberDiaries;
use App\Features\Diary\Serializers\DiarySerializer;
use App\Features\Friend\Queries\ListFriends;
use App\Features\Group\Queries\ListMemberGroups;
use App\Features\Profile\Queries\ShowProfile;
use App\Features\Profile\Serializers\ProfileSerializer;
use App\Features\Timeline\Queries\MemberTimeline;
use App\Models\Member;
use App\Support\Feature;

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
            ? (new RecentMemberDiaries)($viewer, $viewer, UnifiedSections::PHOTOS)->load('images.file')
            : collect();

        return [
            'profile' => self::profile($viewer),
            // Viewer as both viewer and owner: their own memberships and their own friends, at the
            // clearance the profile screen already grants them over themselves.
            'groups' => UnifiedSections::groups(Feature::Group->enabled()
                ? (new ListMemberGroups)->take($viewer, self::GROUPS)
                : collect()),
            'friends' => UnifiedSections::people(Feature::Friend->enabled()
                ? (new ListFriends)->take($viewer, $viewer, self::FRIENDS)
                : collect()),
            'recentPhotos' => UnifiedSections::photos($diaries, Feature::Timeline->enabled()
                ? (new MemberTimeline)->take($viewer, $viewer, UnifiedSections::PHOTOS)
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
}
