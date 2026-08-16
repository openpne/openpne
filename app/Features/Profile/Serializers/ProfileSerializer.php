<?php

namespace App\Features\Profile\Serializers;

use App\Features\Diary\Serializers\DiarySerializer;
use App\Features\Profile\Data\ProfileFieldValue;
use App\Models\Diary;
use App\Models\Group;
use App\Models\Member;
use App\Services\PresetProfileService;
use Illuminate\Support\Collection;

/** Modern surface shape for a member's profile page. */
class ProfileSerializer
{
    /**
     * The self-introduction is promoted out of the field list into `bio` (rendered in the identity
     * header); the remaining fields keep the dl. Visibility is already resolved upstream by
     * ShowProfile, so `$fields` holds only viewer-visible values — no leak path here.
     *
     * @param  'friend'|'sent'|'received'|'none'|null  $friendStatus  null = self or guest viewer
     * @param  Collection<int, ProfileFieldValue>  $fields
     * @return array{owner: array{id: int, name: string, avatarUrl: ?string, avatarColor: ?string, isAi: bool}, isSelf: bool, age: ?int, friendStatus: ?string, bio: ?string, fields: list<array{name: string, caption: string, value: string}>}
     */
    public static function page(Member $owner, Collection $fields, bool $isSelf, string $lang, ?int $age, ?string $friendStatus = null): array
    {
        return [
            'owner' => [
                'id' => $owner->getKey(),
                'name' => $owner->name,
                // The profile header paints this at 80px, the largest avatar outside the editor.
                'avatarUrl' => $owner->avatar?->file?->thumbnailUrl(180, 180, square: true),
                'avatarColor' => $owner->avatar_color?->hex(),
                // Keyed avatarUrl rather than imageUrl (the header paints a larger crop), so the
                // shape stays its own rather than a MemberRefSerializer::ref.
                'isAi' => $owner->isAiAccount(),
            ],
            'isSelf' => $isSelf,
            'age' => $age,
            'friendStatus' => $friendStatus,
            'bio' => self::bio($fields, $lang),
            'fields' => self::fieldRows($fields, $lang),
        ];
    }

    /**
     * The structured field rows, minus the self-introduction (that one is promoted to `bio`).
     *
     * @param  Collection<int, ProfileFieldValue>  $fields
     * @return list<array{name: string, caption: string, value: string}>
     */
    public static function fieldRows(Collection $fields, string $lang): array
    {
        $bioName = self::bioFieldName();

        return $fields
            ->reject(fn (ProfileFieldValue $field): bool => $field->profile->name === $bioName)
            ->map(fn (ProfileFieldValue $field): array => [
                'name' => $field->profile->name,
                'caption' => $field->profile->getCaption($lang),
                'value' => $field->display($lang),
            ])->values()->all();
    }

    /**
     * The self-introduction as a header bio, or null when the field is absent, empty, or outside the
     * viewer's clearance. Reads a ShowProfile result, so the visibility filtering already happened.
     *
     * @param  Collection<int, ProfileFieldValue>  $fields
     */
    public static function bio(Collection $fields, string $lang): ?string
    {
        $name = self::bioFieldName();

        return $fields->first(fn (ProfileFieldValue $field): bool => $field->profile->name === $name)?->display($lang);
    }

    /** The `profiles.name` the self-introduction is stored under. */
    private static function bioFieldName(): string
    {
        return app(PresetProfileService::class)->nameForKey('self_introduction')['name'];
    }

    /**
     * The digest shown to an authenticated viewer: viewer-scoped counts plus a preview of the owner's
     * recent diaries, friends, and joined groups. Grid thumbnails are 320×320 — the profile body
     * column is far wider than the shell's right rail, so RightRailSerializer::rail (sized for that
     * rail) is deliberately not reused.
     *
     * @param  array{diaries: int, activity: int, friends: int, groups: int}  $stats
     * @param  Collection<int, Diary>  $recentDiaries  images.file eager-loaded by the caller (rich rows)
     * @param  Collection<int, Member>  $friends
     * @param  Collection<int, Group>  $groups
     * @return array{stats: array{diaries: int, activity: int, friends: int, groups: int}, recentDiaries: list<array>, friends: list<array{id: int, name: string, imageUrl: ?string, avatarColor: ?string, isAi: bool, href: string}>, groups: list<array{id: int, name: string, imageUrl: ?string, avatarColor: ?string, isAi: bool, href: string}>}
     */
    public static function digest(array $stats, Collection $recentDiaries, Collection $friends, Collection $groups): array
    {
        return [
            'stats' => $stats,
            'recentDiaries' => $recentDiaries->map(fn (Diary $diary): array => DiarySerializer::summary($diary))->all(),
            'friends' => $friends->map(fn (Member $friend): array => [
                'id' => $friend->getKey(),
                'name' => $friend->name,
                'imageUrl' => $friend->avatar?->file?->thumbnailUrl(320, 320, square: true),
                'avatarColor' => $friend->avatar_color?->hex(),
                'isAi' => $friend->isAiAccount(),
                'href' => "/member/{$friend->getKey()}",
            ])->all(),
            'groups' => $groups->map(fn (Group $group): array => [
                'id' => $group->getKey(),
                'name' => $group->name,
                'imageUrl' => $group->image?->thumbnailUrl(320, 320, square: true),
                // Groups carry neither a chosen badge color nor an AI identity; the shared
                // NineTableItem shape keeps both keys so one tile type serves both grids.
                'avatarColor' => null,
                'isAi' => false,
                'href' => "/groups/{$group->getKey()}",
            ])->all(),
        ];
    }
}
