<?php

namespace App\Features\Member\Serializers;

use App\Features\Diary\DiaryVisibility;
use App\Models\Member;
use App\Support\Surface;
use App\Support\SurfaceResolver;
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
        $form = [
            'diary' => [
                'value' => (string) DiaryVisibility::defaultFor($member)->value,
                'options' => array_map(
                    static fn (Visibility $v): array => ['value' => (string) $v->value, 'label' => $v->label()],
                    DiaryVisibility::options(),
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
        ];

        // The Classic/Modern picker is meaningful only where Classic is served; under modern_only it is
        // omitted so a member is never offered a surface they cannot get. The client hides the section
        // when this key is absent.
        if (SurfaceResolver::classicAvailable()) {
            $form['surface'] = [
                'value' => $currentSurface->value,
                'options' => [
                    ['value' => Surface::Classic->value, 'label' => Surface::Classic->label(), 'description' => Surface::Classic->description()],
                    ['value' => Surface::Modern->value, 'label' => Surface::Modern->label(), 'description' => Surface::Modern->description()],
                ],
            ];
        }

        return $form;
    }
}
