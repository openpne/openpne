<?php

namespace App\Features\Member\Serializers;

use App\Features\Diary\DiaryVisibility;
use App\Features\Profile\AgeVisibility;
use App\Models\Member;
use App\Support\Surface;
use App\Support\Visibility;

/**
 * Modern (Inertia) props for the member config page. Mirrors the Classic Blade sections: diary
 * default audience, language, and the binary Classic/Modern surface choice (preselected to the
 * member's current surface). Visibility/Surface labels and surface descriptions are translation
 * keys (run through t() on the client); locale labels are autonyms rendered verbatim.
 */
class MemberConfigSerializer
{
    /** @return array<string, mixed> */
    public static function form(Member $member, Surface $currentSurface): array
    {
        return [
            'diary' => [
                'value' => (string) DiaryVisibility::defaultFor($member)->value,
                'options' => array_map(
                    static fn (Visibility $v): array => ['value' => (string) $v->value, 'label' => $v->label()],
                    DiaryVisibility::options(),
                ),
            ],
            'age' => [
                'value' => (string) AgeVisibility::defaultFor($member)->value,
                'options' => array_map(
                    static fn (Visibility $v): array => ['value' => (string) $v->value, 'label' => $v->label()],
                    AgeVisibility::options(),
                ),
            ],
            'email' => [
                'value' => (string) $member->email,
            ],
            'locale' => [
                'value' => app()->getLocale(),
                'options' => [
                    ['value' => 'ja', 'label' => '日本語'],
                    ['value' => 'en', 'label' => 'English'],
                ],
            ],
            'surface' => [
                'value' => $currentSurface->value,
                'options' => [
                    ['value' => Surface::Classic->value, 'label' => Surface::Classic->label(), 'description' => Surface::Classic->description()],
                    ['value' => Surface::Modern->value, 'label' => Surface::Modern->label(), 'description' => Surface::Modern->description()],
                ],
            ],
        ];
    }
}
