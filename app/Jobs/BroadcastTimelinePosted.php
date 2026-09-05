<?php

namespace App\Jobs;

use App\Features\Timeline\Queries\TimelinePostedRecipients;
use App\Mail\Template\MailTemplate;
use App\Mail\Template\MailTemplateService;
use App\Models\TimelinePost;
use App\Notifications\Settings\NotificationChannel;
use App\Notifications\Settings\NotificationKind;
use App\Notifications\Timeline\TimelinePostedNotification;
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
class BroadcastTimelinePosted implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const CHUNK = 1000;

    /** @param list<int> $mentionedMemberIds the members the post named, snapshotted at dispatch time */
    public function __construct(
        public readonly int $postId,
        public readonly array $mentionedMemberIds = [],
    ) {}

    public function handle(TimelinePostedRecipients $recipients, MailTemplateService $templates): void
    {
        // Saves the audience walk only; the send gate itself is the notification's shouldSend().
        if (! TimelinePostedNotification::feature()->enabled()) {
            return;
        }

        $post = TimelinePost::with('member')->find($this->postId);
        // Deleted before the job ran, or its author withdrew (the post would cascade, but be defensive).
        if ($post === null || $post->member === null) {
            return;
        }

        $audience = $recipients->viewers($post);
        if ($audience === null) {
            return; // a private post has no audience
        }

        // The subtracted set is the mention snapshot the event carried, never one re-derived here, so
        // each member gets exactly one of the two notifications.
        $audience->whereNotIn('id', $this->mentionedMemberIds);

        $author = $post->member;
        $friendIds = $recipients->friendIds($author)->flip();
        // The shared posting template is admin-toggleable; resolved once per broadcast, not per recipient.
        $mailEnabled = $templates->isEnabled(MailTemplate::TimelinePostingNotified);

        $audience->select('id', 'email', 'locale')
            ->chunkById(self::CHUNK, function (EloquentCollection $members) use ($post, $author, $friendIds, $mailEnabled): void {
                $optedOut = $this->optedOut($members->pluck('id'));

                foreach ($members as $member) {
                    $isFriend = $friendIds->has($member->getKey());
                    $canMail = $mailEnabled && $member->email !== null;
                    $channels = $this->channelsFor($optedOut, (int) $member->getKey(), $isFriend, $canMail);
                    if ($channels === []) {
                        continue;
                    }

                    $member->notify(
                        (new TimelinePostedNotification($post, $author, $channels))
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
            ->whereIn('kind', [NotificationKind::TimelineNewPost->value, NotificationKind::TimelineNewPostOnlyFriends->value])
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
            $base = ! isset($optedOut[NotificationKind::TimelineNewPost->value][$ch][$memberId]);

            return $base
                || ($isFriend && ! isset($optedOut[NotificationKind::TimelineNewPostOnlyFriends->value][$ch][$memberId]));
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
