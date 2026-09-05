<?php

namespace App\Features\Profile\Serializers;

use App\Features\Diary\Serializers\DiarySerializer;
use App\Features\Profile\Data\ProfileFieldValue;
use App\Models\Diary;
use App\Models\Group;
use App\Models\Member;
use App\Services\PresetProfileService;
use Illuminate\Support\Collection;

/** Every `$fields` here is a ShowProfile result, already filtered to the viewer's clearance. */
class ProfileSerializer
{
    /**
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
                // Sized for the header's 80px face.
                'avatarUrl' => $owner->avatar?->file?->thumbnailUrl(180, 180, square: true),
                'avatarColor' => $owner->avatar_color?->hex(),
                // Not a MemberRefSerializer::ref: the header paints a larger crop, so this shape
                // keys avatarUrl.
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

    /** @param Collection<int, ProfileFieldValue> $fields */
    public static function bio(Collection $fields, string $lang): ?string
    {
        $name = self::bioFieldName();

        return $fields->first(fn (ProfileFieldValue $field): bool => $field->profile->name === $name)?->display($lang);
    }

    private static function bioFieldName(): string
    {
        return app(PresetProfileService::class)->nameForKey('self_introduction')['name'];
    }

    /**
     * Grid thumbnails are 320×320: the profile body column is wider than the shell's right rail, so
     * RightRailSerializer::rail is not reused.
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
