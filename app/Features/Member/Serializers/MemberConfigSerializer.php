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
 * Labels and descriptions are translation keys, run through `t()` on the client; locale labels are
 * autonyms rendered verbatim.
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

        // The setting is checked at creation only, so switching it off must not lock an existing owner
        // out of the page.
        if ($aiSettings->enabled() || $member->aiAccounts()->exists()) {
            $form['ai'] = ['count' => $member->aiAccounts()->count()];
        }

        // Omitted under `modern_only`, since a member is never offered a surface they cannot get; the
        // client hides the section when the key is absent.
        if (SurfaceResolver::classicAvailable()) {
            $form['surface'] = [
                'value' => $currentSurface->value,
                'options' => [
                    ['value' => Surface::Classic->value, 'label' => Surface::Classic->label(), 'description' => Surface::Classic->description()],
                    ['value' => Surface::Modern->value, 'label' => Surface::Modern->label(), 'description' => Surface::Modern->description()],
                ],
            ];
        }

        // With one selectable look there is nothing to choose; the client hides the row when the key
        // is absent.
        $selectable = LookResolver::selectable();
        if (count($selectable) >= 2) {
            $form['look'] = [
                'current' => self::chosenLook($member, $selectable)?->label(),
                'default' => LookResolver::siteDefault()->label(),
            ];
        }

        return $form;
    }

    /** @return array<string, mixed> */
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
     * The stored choice, not the resolved look: an undecided member reads as following the site
     * default. A stored look the site no longer offers reads as following too.
     *
     * @param  list<Look>  $selectable
     */
    private static function chosenLook(Member $member, array $selectable): ?Look
    {
        $stored = $member->preferredLook();

        return $stored !== null && in_array($stored, $selectable, true) ? $stored : null;
    }
}
