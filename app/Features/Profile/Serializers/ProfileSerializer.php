<?php

namespace App\Features\Profile\Serializers;

use App\Features\Profile\Data\ProfileFieldValue;
use App\Services\PresetProfileService;
use Illuminate\Support\Collection;

/** Every `$fields` here is a ShowProfile result, already filtered to the viewer's clearance. */
class ProfileSerializer
{
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
}
