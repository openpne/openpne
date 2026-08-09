<?php

declare(strict_types=1);

namespace App\Notifications\Push;

use App\Features\Notifications\NotificationKindLabel;
use App\Features\Notifications\Queries\CountUnreadNotifications;
use App\Models\Member;
use App\Support\PushDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Tells a member's subscribed devices that a feed row was written for them. Not a notification kind
 * of its own: eligibility was settled when that row was written, so this carries no catalog opt-in
 * and no feature gate — it is the same event, delivered again to a device.
 *
 * The constructor takes scalars rather than models (no SerializesModels): the actor may withdraw
 * between the feed row and the send, and a model this notification cannot restore would fail the
 * queued job instead of degrading to the withdrawn-member label the feed itself shows.
 */
final class WebPushNudge extends Notification implements ShouldQueue
{
    use Queueable;

    /** Collapses the previous nudge on the device — the payload only ever says "there is something new". */
    private const TAG = 'openpne-notifications';

    public function __construct(
        private readonly ?string $kind,
        private readonly ?string $reason,
        private readonly ?int $actorId,
    ) {}

    /** @return list<string> */
    public function via(Member $notifiable): array
    {
        return [WebPushChannel::class];
    }

    /**
     * Re-checked here rather than only in the listener: the queue may run this long after the feed
     * row, by which time the keys can be gone or the member can have paused delivery.
     */
    public function shouldSend(Member $notifiable, string $channel): bool
    {
        return WebPushConfig::configured() && $notifiable->pushDelivery() === PushDelivery::Enabled;
    }

    public function toWebPush(Member $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title(sns_name())
            ->body(NotificationKindLabel::for($this->kind, $this->reason, $this->actorName()))
            ->icon(app_icon_url(192))
            ->tag(self::TAG)
            // The count is what the device paints as its app badge, so it is read at send time, not
            // at feed-row time; the page re-syncs it from the authoritative count on next open.
            ->data([
                'url' => '/notifications',
                'unreadCount' => (new CountUnreadNotifications)($notifiable),
            ]);
    }

    /** Null once the actor has withdrawn — NotificationKindLabel fills the same fallback the feed shows. */
    private function actorName(): ?string
    {
        return $this->actorId === null ? null : Member::find($this->actorId)?->name;
    }
}
