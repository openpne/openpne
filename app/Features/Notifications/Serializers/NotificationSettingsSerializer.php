<?php

namespace App\Features\Notifications\Serializers;

use App\Models\Member;
use App\Notifications\Settings\NotificationChannel;
use App\Notifications\Settings\NotificationKind;

/**
 * dependOnNot rides along so the Modern page can render an "(x only)" variant pair as one three-state
 * control.
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
            // A switched-off unit sends nothing, so its opt-ins are not offered; the stored rows stay put
            // and reappear with it.
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
     * True only where the default is an admin setting (NotificationKind::hasSiteDefault); elsewhere an
     * absent row means a default nobody can move, the talk broadcast's mail channel included.
     *
     * @return array{web: bool, mail: bool}
     */
    private static function siteDefault(Member $viewer, NotificationKind $kind): array
    {
        $inherited = static fn (NotificationChannel $channel): bool => $kind->hasSiteDefault($channel)
            && ! $viewer->hasNotificationSetting($kind, $channel);

        return [
            'web' => $inherited(NotificationChannel::Web),
            'mail' => $inherited(NotificationChannel::Mail),
        ];
    }
}
