<?php

namespace App\Features\Notifications\Serializers;

use App\Models\Member;
use App\Notifications\Settings\NotificationChannel;
use App\Notifications\Settings\NotificationKind;

/**
 * The settings-form shape for the notification catalog, shared by both surfaces: wired kinds
 * only (an unwired kind has no sender, so a toggle would gate nothing), grouped by category in
 * registry order. dependOnNot rides along so the Modern page can render an "(x only)" variant
 * pair as one three-state control.
 */
class NotificationSettingsSerializer
{
    /**
     * @return array{groups: list<array{key: string, caption: string, kinds: list<array{kind: string, caption: string, dependOnNot: ?string, web: bool, mail: bool, siteDefault: array{web: bool, mail: bool}}>}>}
     */
    public static function form(Member $viewer): array
    {
        $groups = [];

        foreach (NotificationKind::wiredCases() as $kind) {
            $category = $kind->category();
            // A switched-off unit sends nothing, so its opt-ins are not choices to offer. The stored
            // rows stay put and reappear with the unit.
            if (! $category->feature()->enabled()) {
                continue;
            }

            $groups[$category->value] ??= [
                'key' => $category->value,
                'caption' => $category->caption(),
                'kinds' => [],
            ];
            $groups[$category->value]['kinds'][] = [
                'kind' => $kind->value,
                'caption' => $kind->caption(),
                'dependOnNot' => $kind->dependOnNot()?->value,
                'web' => $viewer->wantsNotification($kind, NotificationChannel::Web),
                'mail' => $viewer->wantsNotification($kind, NotificationChannel::Mail),
                'siteDefault' => self::siteDefault($viewer, $kind),
            ];
        }

        return ['groups' => array_values($groups)];
    }

    /**
     * Whether the value shown on each channel is the site's rather than the member's own — what the
     * surfaces label "(default)". Only a kind whose default is an admin setting can be inherited at
     * all (NotificationKind::hasSiteDefault); for every other kind the shown value is the member's
     * either way, since an absent row there means a default nobody can move.
     *
     * @return array{web: bool, mail: bool}
     */
    private static function siteDefault(Member $viewer, NotificationKind $kind): array
    {
        if (! $kind->hasSiteDefault()) {
            return ['web' => false, 'mail' => false];
        }

        return [
            'web' => ! $viewer->hasNotificationSetting($kind, NotificationChannel::Web),
            'mail' => ! $viewer->hasNotificationSetting($kind, NotificationChannel::Mail),
        ];
    }
}
