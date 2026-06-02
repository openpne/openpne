<?php

namespace App\Features\Profile\Serializers;

use App\Features\Profile\Data\ProfileFieldValue;
use App\Models\Member;
use Illuminate\Support\Collection;

/** Modern surface shape for a member's profile page. */
class ProfileSerializer
{
    /**
     * @param  Collection<int, ProfileFieldValue>  $fields
     * @return array{owner: array{id: int, name: string, avatarUrl: ?string}, isSelf: bool, fields: list<array{name: string, caption: string, value: string}>}
     */
    public static function page(Member $owner, Collection $fields, bool $isSelf, string $lang): array
    {
        return [
            'owner' => [
                'id' => $owner->getKey(),
                'name' => $owner->name,
                'avatarUrl' => $owner->primaryImage?->file?->thumbnailUrl(120, 120, square: true),
            ],
            'isSelf' => $isSelf,
            'fields' => $fields->map(fn (ProfileFieldValue $field): array => [
                'name' => $field->profile->name,
                'caption' => $field->profile->getCaption($lang),
                'value' => $field->display($lang),
            ])->values()->all(),
        ];
    }
}
