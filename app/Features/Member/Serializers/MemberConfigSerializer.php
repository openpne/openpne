<?php

namespace App\Features\Member\Serializers;

use App\Features\AiAccount\AiAccountSettings;
use App\Features\Diary\DiaryVisibility;
use App\Models\Member;
use App\Support\Feature;
use App\Support\Look;
use App\Support\LookResolver;
use App\Support\Surface;
use App\Support\SurfaceResolver;
use App\Support\Visibility;

/**
 * Modern (Inertia) props for the member config page. Mirrors the Classic Blade sections: diary
 * default audience, language, and the binary Classic/Modern surface choice (preselected to the
 * member's current surface), plus a row into the layout picker, which has no Classic twin — a look
 * only changes how Modern renders. Visibility/Surface/Look labels and descriptions are translation
 * keys (run through t() on the client); locale labels are autonyms rendered verbatim.
 */
class MemberConfigSerializer
{
    /** @return array<string, mixed> */
    public static function form(Member $member, Surface $currentSurface, AiAccountSettings $aiSettings): array
    {
        $form = [
            'email' => [
                'value' => (string) $member->email,
            ],
            // Status only; secret material stays on the MFA detail page's own serializer.
            'mfa' => [
                'enabled' => $member->hasEnabledTwoFactorAuthentication(),
            ],
            'locale' => [
                'value' => app()->getLocale(),
                'options' => [
                    ['value' => 'ja', 'label' => '日本語'],
                    ['value' => 'en', 'label' => 'English'],
                ],
            ],
        ];

        // The audience default is offered only while diaries are, since its POST target is gated too.
        if (Feature::Diary->enabled()) {
            $form['diary'] = [
                'value' => (string) DiaryVisibility::defaultFor($member)->value,
                'options' => array_map(
                    static fn (Visibility $v): array => ['value' => (string) $v->value, 'label' => $v->label()],
                    DiaryVisibility::options(),
                ),
            ];
        }

        // Offered while the site offers AI accounts — and, because the setting is checked at creation
        // only, to anyone who already owns one: switching it off must not lock an owner out of the
        // page where they empty and delete what they have.
        if ($aiSettings->enabled() || $member->aiAccounts()->exists()) {
            $form['ai'] = ['count' => $member->aiAccounts()->count()];
        }

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

        // Offered only where there is a choice to make: with one selectable look the picker would be
        // a single card the member cannot move off. The client hides the row when the key is absent.
        $selectable = LookResolver::selectable();
        if (count($selectable) >= 2) {
            $form['look'] = [
                // Labels only — the row states the current choice and links out; the picker page
                // carries the options and what separates them.
                'current' => self::chosenLook($member, $selectable)?->label(),
                'default' => LookResolver::siteDefault()->label(),
            ];
        }

        return $form;
    }

    /**
     * The layout picker's own props: what may be chosen, and what is chosen now.
     *
     * @return array<string, mixed>
     */
    public static function lookForm(Member $member): array
    {
        $selectable = LookResolver::selectable();
        $default = LookResolver::siteDefault();

        return [
            'options' => array_map(
                static fn (Look $look): array => [
                    'value' => $look->value,
                    'label' => $look->label(),
                    'description' => $look->description(),
                ],
                $selectable,
            ),
            'current' => self::chosenLook($member, $selectable)?->value,
            'default' => ['value' => $default->value, 'label' => $default->label()],
        ];
    }

    /**
     * The stored choice, not the resolved look: "follow the site default" is a choice of its own,
     * and an undecided member must read as following rather than as having picked whatever they are
     * currently shown. Filtered through the same set as the resolver, so a stored look the site no
     * longer offers reads as following too — not as a card that is not there.
     *
     * @param  list<Look>  $selectable
     */
    private static function chosenLook(Member $member, array $selectable): ?Look
    {
        $stored = $member->preferredLook();

        return $stored !== null && in_array($stored, $selectable, true) ? $stored : null;
    }
}
