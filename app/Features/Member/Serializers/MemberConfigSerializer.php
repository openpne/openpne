<?php

namespace App\Features\Member\Serializers;

use App\Features\Diary\DiaryVisibility;
use App\Models\Member;
use App\Support\Surface;
use App\Support\Visibility;

/**
 * Modern (Inertia) props for the member config page. Mirrors the Classic Blade sections: diary
 * default audience, language, and the tri-state surface choice (empty = follow the site default).
 * Visibility/Surface labels are translation keys (run through t() on the client); locale labels are
 * autonyms rendered verbatim.
 */
class MemberConfigSerializer
{
    /** @return array<string, mixed> */
    public static function form(Member $member): array
    {
        return [
            'diary' => [
                'value' => (string) DiaryVisibility::defaultFor($member)->value,
                'options' => array_map(
                    static fn (Visibility $v): array => ['value' => (string) $v->value, 'label' => $v->label()],
                    DiaryVisibility::options(),
                ),
            ],
            'locale' => [
                'value' => app()->getLocale(),
                'options' => [
                    ['value' => 'ja', 'label' => '日本語'],
                    ['value' => 'en', 'label' => 'English'],
                ],
            ],
            'surface' => [
                'value' => $member->preferredSurface()?->value ?? '',
                'options' => [
                    ['value' => '', 'label' => 'Site default'],
                    ['value' => Surface::Classic->value, 'label' => Surface::Classic->label()],
                    ['value' => Surface::Modern->value, 'label' => Surface::Modern->label()],
                ],
            ],
        ];
    }
}
