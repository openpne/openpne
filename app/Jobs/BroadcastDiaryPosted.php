<?php

namespace App\Jobs;

use App\Features\Diary\Queries\DiaryPostedRecipients;
use App\Models\Diary;
use App\Models\Member;
use App\Notifications\Diary\DiaryPostedNotification;
use App\Notifications\Settings\NotificationChannel;
use App\Notifications\Settings\NotificationKind;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The chunked walk, the single opt-out query and the union of the two catalog kinds are in
 * docs/internals/notifications.md, "Broadcast fan-out".
 */
class BroadcastDiaryPosted implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const CHUNK = 1000;

    public function __construct(public readonly int $diaryId) {}

    public function handle(DiaryPostedRecipients $recipients): void
    {
        // Saves the audience walk only; the send gate itself is the notification's shouldSend().
        if (! DiaryPostedNotification::feature()->enabled()) {
            return;
        }

        $diary = Diary::with('member')->find($this->diaryId);
        // Deleted before the job ran, or its author withdrew (the diary would cascade, but be defensive).
        if ($diary === null || $diary->member === null) {
            return;
        }

        $audience = $recipients->viewers($diary);
        if ($audience === null) {
            return; // a private diary has no audience
        }

        $author = $diary->member;
        $friendIds = $recipients->friendIds($author)->flip();

        $audience->select('id', 'email', 'locale')
            ->chunkById(self::CHUNK, function (EloquentCollection $members) use ($diary, $author, $friendIds): void {
                $optedOut = $this->optedOut($members->pluck('id'));

                foreach ($members as $member) {
                    $isFriend = $friendIds->has($member->getKey());
                    $channels = $this->channelsFor($optedOut, (int) $member->getKey(), $isFriend, $member->email !== null);
                    if ($channels === []) {
                        continue;
                    }

                    $member->notify(
                        (new DiaryPostedNotification($diary, $author, $channels))
                            ->locale($member->locale ?? (string) config('app.locale')),
                    );
                }
            });
    }

    /**
     * The opted-out set for this chunk in one indexed query (kind, channel, is_enabled, member_id):
     * $out[kind][channel][member_id] = true. Everyone absent defaults to on.
     *
     * @param  Collection<int, int>  $ids
     * @return array<string, array<string, array<int, true>>>
     */
    private function optedOut(Collection $ids): array
    {
        $out = [];

        DB::table('member_notification_settings')
            ->whereIn('kind', [NotificationKind::DiaryNewPost->value, NotificationKind::DiaryNewPostOnlyFriends->value])
            ->where('is_enabled', false)
            ->whereIn('member_id', $ids)
            ->get(['member_id', 'kind', 'channel'])
            ->each(function (object $row) use (&$out): void {
                $out[$row->kind][$row->channel][(int) $row->member_id] = true;
            });

        return $out;
    }

    /**
     * @param  array<string, array<string, array<int, true>>>  $optedOut
     * @return list<string>
     */
    private function channelsFor(array $optedOut, int $memberId, bool $isFriend, bool $canMail): array
    {
        $wants = function (NotificationChannel $channel) use ($optedOut, $memberId, $isFriend): bool {
            $ch = $channel->value;
            $base = ! isset($optedOut[NotificationKind::DiaryNewPost->value][$ch][$memberId]);

            return $base
                || ($isFriend && ! isset($optedOut[NotificationKind::DiaryNewPostOnlyFriends->value][$ch][$memberId]));
        };

        $channels = [];
        // A login-impossible member (no address) still gets the in-app feed row, but never a mail.
        if ($canMail && $wants(NotificationChannel::Mail)) {
            $channels[] = 'mail';
        }
        if ($wants(NotificationChannel::Web)) {
            $channels[] = 'database';
        }

        return $channels;
    }
}
