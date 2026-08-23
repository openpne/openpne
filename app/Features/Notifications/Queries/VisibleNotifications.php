<?php

declare(strict_types=1);

namespace App\Features\Notifications\Queries;

use App\Notifications\FeatureNotificationMap;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Hides a switched-off unit's rows from every notification surface — the feed, the header center,
 * the badge counts — by the non-null `type` column (see FeatureNotificationMap).
 *
 * The rows themselves are never touched here: mark-all-read runs through this too, so a hidden row is
 * still unread when its unit comes back, exactly as the member left it. One kind does delete its own
 * rows, outside this filter: the talk broadcast keeps a single row per room
 * (App\Features\GroupTalk\GroupTalkRoomNotificationRows).
 */
final class VisibleNotifications
{
    /**
     * @param  MorphMany<DatabaseNotification, *>  $query
     * @return MorphMany<DatabaseNotification, *>
     */
    public static function apply(MorphMany $query): MorphMany
    {
        $disabled = FeatureNotificationMap::disabledTypes();

        return $disabled === [] ? $query : $query->whereNotIn('type', $disabled);
    }

    /** The same rule against a row already in hand, so a caller holding one needs no second query. */
    public static function hides(DatabaseNotification $row): bool
    {
        return in_array($row->type, FeatureNotificationMap::disabledTypes(), true);
    }
}
