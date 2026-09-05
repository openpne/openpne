<?php

namespace App\Jobs;

use App\Features\GroupTalk\Queries\GroupTalkBroadcastRecipients;
use App\Mail\Template\MailTemplate;
use App\Mail\Template\MailTemplateService;
use App\Models\GroupMessage;
use App\Notifications\GroupTalk\GroupTalkMessagePostedNotification;
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
 * Why this fan-out reads the kind's rows in both polarities, and how it stays cheap on a site where
 * nobody opted in, are in docs/internals/notifications.md, "Broadcast fan-out".
 */
class BroadcastGroupMessagePosted implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * How long the dispatch waits before this runs. Long enough for a member sitting in the room to
     * mark it read (the client debounces ~700ms) so the already-read gate catches them, short enough
     * that someone away from the app is not kept waiting for it.
     */
    public const GRACE_SECONDS = 10;

    private const CHUNK = 1000;

    /** @param list<int> $mentionedMemberIds the members the message named, snapshotted at dispatch time */
    public function __construct(
        public readonly int $messageId,
        public readonly array $mentionedMemberIds = [],
    ) {}

    public function handle(GroupTalkBroadcastRecipients $recipients, MailTemplateService $templates): void
    {
        // Saves the audience walk only; the send gate itself is the notification's shouldSend().
        if (! GroupTalkMessagePostedNotification::feature()->enabled()) {
            return;
        }

        $message = GroupMessage::with('group', 'author')->find($this->messageId);
        // Deleted before the job ran, its group dissolved, or its author withdrew.
        if ($message === null || $message->group === null || $message->author === null) {
            return;
        }

        $kind = NotificationKind::GroupTalkNewMessage;
        $webDefault = $kind->defaultEnabled(NotificationChannel::Web);
        $mailDefault = $kind->defaultEnabled(NotificationChannel::Mail);
        // The shared template is admin-toggleable; resolved once per broadcast, not per recipient.
        $mailEnabled = $templates->isEnabled(MailTemplate::GroupTalkMessageNotified);

        $optInsOnly = ! $webDefault && ! $mailDefault;
        if ($optInsOnly && ! $this->anyoneOptedIn($kind)) {
            return;
        }

        $audience = $recipients->viewers($message->group, $message->author, $this->mentionedMemberIds);
        if ($optInsOnly) {
            $recipients->restrictToOptedIn($audience, $kind);
        }

        $author = $message->author;

        $audience->select('id', 'email', 'locale')
            ->chunkById(self::CHUNK, function (EloquentCollection $members) use ($message, $author, $kind, $webDefault, $mailDefault, $mailEnabled): void {
                $explicit = $this->explicitChoices($kind, $members->pluck('id'));

                foreach ($members as $member) {
                    $wants = fn (NotificationChannel $channel, bool $default): bool => $explicit[$channel->value][(int) $member->getKey()] ?? $default;

                    $channels = [];
                    // A login-impossible member (no address) still gets the in-app row, but no mail.
                    if ($mailEnabled && $member->email !== null && $wants(NotificationChannel::Mail, $mailDefault)) {
                        $channels[] = 'mail';
                    }
                    if ($wants(NotificationChannel::Web, $webDefault)) {
                        $channels[] = 'database';
                    }
                    if ($channels === []) {
                        continue;
                    }

                    $member->notify(
                        (new GroupTalkMessagePostedNotification($author, $message, $channels))
                            ->locale($member->locale ?? (string) config('app.locale')),
                    );
                }
            });
    }

    /** Whether anyone at all has opted in, on either channel — one indexed probe per channel. */
    private function anyoneOptedIn(NotificationKind $kind): bool
    {
        foreach (NotificationChannel::cases() as $channel) {
            $optedIn = DB::table('member_notification_settings')
                ->where('kind', $kind->value)
                ->where('channel', $channel->value)
                ->where('is_enabled', true)
                ->limit(1)
                ->exists();

            if ($optedIn) {
                return true;
            }
        }

        return false;
    }

    /**
     * This chunk's explicit rows in one indexed query (kind, channel, is_enabled, member_id):
     * $out[channel][member_id] = is_enabled. Both polarities, because a member absent here takes a
     * default that may itself be false.
     *
     * @param  Collection<int, int>  $ids
     * @return array<string, array<int, bool>>
     */
    private function explicitChoices(NotificationKind $kind, Collection $ids): array
    {
        $out = [];

        DB::table('member_notification_settings')
            ->where('kind', $kind->value)
            ->whereIn('member_id', $ids)
            ->get(['member_id', 'channel', 'is_enabled'])
            ->each(function (object $row) use (&$out): void {
                $out[$row->channel][(int) $row->member_id] = (bool) $row->is_enabled;
            });

        return $out;
    }
}
