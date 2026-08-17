<?php

namespace App\Features\Profile\Serializers;

use App\Features\Diary\Queries\RecentMemberDiaries;
use App\Features\Diary\Serializers\DiarySerializer;
use App\Features\Friend\Queries\ListFriends;
use App\Features\Group\Queries\ListMemberGroups;
use App\Features\Home\Serializers\UnifiedSections;
use App\Features\Profile\Data\ProfileFieldValue;
use App\Features\Timeline\Queries\MemberTimeline;
use App\Models\Member;
use App\Support\Feature;
use Illuminate\Support\Collection;

/**
 * The unified member page (SnsSettingKey::ModernUnifiedHome): the same grammar as the unified home,
 * about somebody else. Viewer and owner are separate parameters here — that is the whole difference
 * from its home sibling, and why every source below is a query that takes both and resolves the
 * owner's rows at the viewer's clearance rather than at the owner's.
 *
 * The gates that decide whether this page may be drawn at all (the owner→viewer block, the
 * guest/web-public rule) and the profile fields' own visibility are the controller's, already applied:
 * what arrives here is what the viewer may see, and this only shapes it.
 */
final class UnifiedMemberSerializer
{
    /** Rows in the "recent %diaries%" list — a lead-in to the archive, not the archive. */
    private const DIARY_ROWS = 3;

    /** Group cards: two full rows of three. */
    private const GROUPS = 6;

    /** Faces in the people grid: the seat map's two rows, four and five. */
    private const FRIENDS = 9;

    /**
     * @param  Collection<int, ProfileFieldValue>  $fields  already resolved at the viewer's clearance
     * @param  'friend'|'sent'|'received'|'none'|null  $friendStatus  null = self, or no entry to offer
     * @return array{profile: array{id: int, name: string, avatarUrl: string|null, avatarUrlLarge: string|null, avatarColor: string|null, isAi: bool, bio: string|null, age: int|null, isSelf: bool, friendStatus: string|null}, fields: list<array{name: string, caption: string, value: string}>, groups: list<array{id: int, name: string, imageUrl: string|null, href: string}>, friends: list<array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool, href: string}>, recentPhotos: list<array{source: 'diary'|'timeline', href: string, image: array}>, recentDiaries: list<array>}
     */
    public static function page(
        Member $viewer,
        Member $owner,
        Collection $fields,
        bool $isSelf,
        string $lang,
        ?int $age,
        ?string $friendStatus,
    ): array {
        // The same rows feed both halves of the "latest" section: the newest few as list rows, all of
        // them as picture sources.
        $diaries = Feature::Diary->enabled()
            ? (new RecentMemberDiaries)($viewer, $owner, UnifiedSections::PHOTOS)->load('images.file')
            : collect();

        return [
            'profile' => self::profile($owner, $fields, $lang, $isSelf, $age, $friendStatus),
            // The resume-like field rows member/show shows; without them the unified page would make
            // a member's declared profile unreachable while the switch is on.
            'fields' => ProfileSerializer::fieldRows($fields, $lang),
            // The owner's memberships, as the profile digest lists them: a joined group is public to
            // anyone who may open the page.
            'groups' => UnifiedSections::groups(Feature::Group->enabled()
                ? (new ListMemberGroups)->take($owner, self::GROUPS)
                : collect()),
            'friends' => UnifiedSections::people(Feature::Friend->enabled()
                ? (new ListFriends)->takeNewest($viewer, $owner, self::FRIENDS)
                : collect()),
            'recentPhotos' => UnifiedSections::photos($diaries, Feature::Timeline->enabled()
                ? (new MemberTimeline)->take($viewer, $owner, UnifiedSections::PHOTOS)
                : collect()),
            'recentDiaries' => $diaries->take(self::DIARY_ROWS)->map([DiarySerializer::class, 'summary'])->values()->all(),
        ];
    }

    /**
     * The identity block, plus what the action row under it is drawn from. The two avatar sizes are
     * the hero's `srcset` rungs — a wide crop across the whole content column, which the 180px the
     * digest profile screen's round face uses would not survive.
     *
     * @param  Collection<int, ProfileFieldValue>  $fields
     * @return array{id: int, name: string, avatarUrl: string|null, avatarUrlLarge: string|null, avatarColor: string|null, isAi: bool, bio: string|null, age: int|null, isSelf: bool, friendStatus: string|null}
     */
    private static function profile(
        Member $owner,
        Collection $fields,
        string $lang,
        bool $isSelf,
        ?int $age,
        ?string $friendStatus,
    ): array {
        $file = $owner->avatar?->file;

        return [
            'id' => $owner->getKey(),
            'name' => $owner->name,
            'avatarUrl' => $file?->thumbnailUrl(640, 640, square: true),
            'avatarUrlLarge' => $file?->thumbnailUrl(1200, 1200, square: true),
            'avatarColor' => $owner->avatar_color?->hex(),
            'isAi' => $owner->isAiAccount(),
            'bio' => ProfileSerializer::bio($fields, $lang),
            'age' => $age,
            'isSelf' => $isSelf,
            'friendStatus' => $friendStatus,
        ];
    }
}
