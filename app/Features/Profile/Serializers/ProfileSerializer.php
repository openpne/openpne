<?php

namespace App\Features\Profile\Serializers;

use App\Features\Diary\Serializers\DiarySerializer;
use App\Features\Profile\Data\ProfileFieldValue;
use App\Models\Community;
use App\Models\Diary;
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
     * @return array{owner: array{id: int, name: string, avatarUrl: ?string, avatarColor: ?string}, isSelf: bool, age: ?int, friendStatus: ?string, bio: ?string, fields: list<array{name: string, caption: string, value: string}>}
     */
    public static function page(Member $owner, Collection $fields, bool $isSelf, string $lang, ?int $age, ?string $friendStatus = null): array
    {
        $bioName = app(PresetProfileService::class)->nameForKey('self_introduction')['name'];
        $bioField = $fields->first(fn (ProfileFieldValue $field): bool => $field->profile->name === $bioName);

        return [
            'owner' => [
                'id' => $owner->getKey(),
                'name' => $owner->name,
                'avatarUrl' => $owner->avatar?->file?->thumbnailUrl(120, 120, square: true),
                'avatarColor' => $owner->avatar_color?->hex(),
            ],
            'isSelf' => $isSelf,
            'age' => $age,
            'friendStatus' => $friendStatus,
            'bio' => $bioField?->display($lang),
            'fields' => $fields
                ->reject(fn (ProfileFieldValue $field): bool => $field->profile->name === $bioName)
                ->map(fn (ProfileFieldValue $field): array => [
                    'name' => $field->profile->name,
                    'caption' => $field->profile->getCaption($lang),
                    'value' => $field->display($lang),
                ])->values()->all(),
        ];
    }

    /**
     * The digest shown to an authenticated viewer: viewer-scoped counts plus a preview of the owner's
     * recent diaries, friends, and joined communities. Grid thumbnails are 320×320 — the profile body
     * column is far wider than the shell's right rail, so RightRailSerializer::rail (sized for that
     * rail) is deliberately not reused.
     *
     * @param  array{diaries: int, activity: int, friends: int, communities: int}  $stats
     * @param  Collection<int, Diary>  $recentDiaries  images.file eager-loaded by the caller (rich rows)
     * @param  Collection<int, Member>  $friends
     * @param  Collection<int, Community>  $communities
     * @return array{stats: array{diaries: int, activity: int, friends: int, communities: int}, recentDiaries: list<array>, friends: list<array{id: int, name: string, imageUrl: ?string, avatarColor: ?string, href: string}>, communities: list<array{id: int, name: string, imageUrl: ?string, avatarColor: ?string, href: string}>}
     */
    public static function digest(array $stats, Collection $recentDiaries, Collection $friends, Collection $communities): array
    {
        return [
            'stats' => $stats,
            'recentDiaries' => $recentDiaries->map(fn (Diary $diary): array => DiarySerializer::summary($diary))->all(),
            'friends' => $friends->map(fn (Member $friend): array => [
                'id' => $friend->getKey(),
                'name' => $friend->name,
                'imageUrl' => $friend->avatar?->file?->thumbnailUrl(320, 320, square: true),
                'avatarColor' => $friend->avatar_color?->hex(),
                'href' => "/member/{$friend->getKey()}",
            ])->all(),
            'communities' => $communities->map(fn (Community $community): array => [
                'id' => $community->getKey(),
                'name' => $community->name,
                'imageUrl' => $community->image?->thumbnailUrl(320, 320, square: true),
                // Communities carry no chosen badge color; the shared NineTableItem shape keeps the key.
                'avatarColor' => null,
                'href' => "/community/{$community->getKey()}",
            ])->all(),
        ];
    }
}
