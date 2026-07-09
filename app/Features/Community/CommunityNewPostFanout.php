<?php

namespace App\Features\Community;

use App\Features\Community\Queries\CommunityNewPostRecipients;
use App\Models\Community;
use App\Models\Member;
use App\Notifications\Settings\NotificationKind;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Shared new-community-posting fan-out (topic and event new-post). Walks the audience in id-ordered
 * chunks and resolves each chunk's channels from ONE opt-out query over the member_notification_settings
 * fan-out index — never a per-recipient cold read. Unlike the diary broadcast there is no friends-only
 * variant, so the gate is the single new-post kind; the mail leg additionally needs the shared
 * (configurable) community-posting template to be enabled, checked once by the caller.
 */
class CommunityNewPostFanout
{
    private const CHUNK = 1000;

    public function __construct(private readonly CommunityNewPostRecipients $recipients) {}

    /**
     * @param  bool  $mailTemplateEnabled  the community-posting template's admin toggle, resolved once
     * @param  callable(list<string>): Notification  $makeNotification  builds the notification for decided channels
     */
    public function run(Community $community, Member $author, NotificationKind $kind, bool $mailTemplateEnabled, callable $makeNotification): void
    {
        $this->recipients->viewers($community, $author)
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
