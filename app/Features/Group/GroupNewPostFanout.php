<?php

namespace App\Features\Group;

use App\Models\Member;
use App\Notifications\Settings\NotificationKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Shared group-posting fan-out (topic/event new-post and comment broadcast). Walks the given member
 * audience in id-ordered chunks and resolves each chunk's channels from ONE opt-out query over the
 * member_notification_settings fan-out index — never a per-recipient cold read. Unlike the diary
 * broadcast there is no friends-only variant, so the gate is the single kind; the mail leg additionally
 * needs the shared (configurable) group-posting template to be enabled, checked once by the caller.
 * The audience itself (who to reach, minus author / already-notified) is the caller's to build.
 */
class GroupNewPostFanout
{
    private const CHUNK = 1000;

    /**
     * @param  Builder<Member>  $audience  the members to reach (already excluding author / reply-related)
     * @param  bool  $mailTemplateEnabled  the group-posting template's admin toggle, resolved once
     * @param  callable(list<string>): Notification  $makeNotification  builds the notification for decided channels
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
     * The opted-out set for this chunk in one indexed query: $out[channel][member_id] = true. Everyone
     * absent defaults to on.
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
