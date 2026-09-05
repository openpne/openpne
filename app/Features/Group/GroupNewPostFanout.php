<?php

namespace App\Features\Group;

use App\Models\Member;
use App\Notifications\Settings\NotificationKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** See docs/internals/notifications.md, "Broadcast fan-out". */
class GroupNewPostFanout
{
    private const CHUNK = 1000;

    /**
     * @param  Builder<Member>  $audience  the members to reach, already excluding the author and anyone the caller notified inline
     * @param  bool  $mailTemplateEnabled  the group-posting template's admin toggle, resolved once by the caller
     * @param  callable(list<string>): Notification  $makeNotification
     */
    public function run(Builder $audience, NotificationKind $kind, bool $mailTemplateEnabled, callable $makeNotification): void
    {
        $audience
            ->select('id', 'email', 'locale')
            ->chunkById(self::CHUNK, function (EloquentCollection $members) use ($kind, $mailTemplateEnabled, $makeNotification): void {
                $optedOut = $this->optedOut($kind, $members->pluck('id'));

                foreach ($members as $member) {
                    $wants = fn (string $channel): bool => ! isset($optedOut[$channel][(int) $member->getKey()]);

                    $channels = [];
                    // A login-impossible member (no address) still gets the in-app feed row, but no mail.
                    if ($mailTemplateEnabled && $member->email !== null && $wants('mail')) {
                        $channels[] = 'mail';
                    }
                    if ($wants('web')) {
                        $channels[] = 'database';
                    }
                    if ($channels === []) {
                        continue;
                    }

                    $member->notify(
                        $makeNotification($channels)->locale($member->locale ?? (string) config('app.locale')),
                    );
                }
            });
    }

    /**
     * Everyone absent from the result defaults to on.
     *
     * @param  Collection<int, int>  $ids
     * @return array<string, array<int, true>>
     */
    private function optedOut(NotificationKind $kind, Collection $ids): array
    {
        $out = [];

        DB::table('member_notification_settings')
            ->where('kind', $kind->value)
            ->where('is_enabled', false)
            ->whereIn('member_id', $ids)
            ->get(['member_id', 'channel'])
            ->each(function (object $row) use (&$out): void {
                $out[$row->channel][(int) $row->member_id] = true;
            });

        return $out;
    }
}
