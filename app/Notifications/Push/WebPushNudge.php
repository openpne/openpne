<?php

declare(strict_types=1);

namespace App\Notifications\Push;

use App\Features\Member\MemberDisplayName;
use App\Features\Notifications\NotificationKindLabel;
use App\Features\Notifications\NotificationPreview;
use App\Features\Notifications\Queries\CountUnreadNotifications;
use App\Models\Member;
use App\Support\PushDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Carries no catalog opt-in and no feature gate, and is built from scalars rather than models
 * (docs/internals/notifications.md, "Web push").
 */
final class WebPushNudge extends Notification implements ShouldQueue
{
    use Queueable;

    private const TAG = 'openpne-notifications';

    /**
     * Must stay under the queue's retry_after, past which the job is handed out again mid-send and
     * every device that answered is pushed twice (docs/internals/outbound-http.md, "The push
     * endpoint seam").
     */
    public int $timeout = 60;

    /** @param array<string, mixed> $data the feed row's data: a kind, a reason and entity ids, nothing hydrated */
    public function __construct(
        private readonly array $data,
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

    /**
     * The site's name is the notification's attribution line, which the device draws from the manifest;
     * putting it in the title printed it twice.
     */
    public function toWebPush(Member $notifiable): WebPushMessage
    {
        $kind = is_string($this->data['kind'] ?? null) ? $this->data['kind'] : null;
        $reason = is_string($this->data['reason'] ?? null) ? $this->data['reason'] : null;

        $message = (new WebPushPayload)
            ->title(NotificationKindLabel::for($kind, $reason, $this->actorName()))
            ->icon(app_icon_url(192))
            ->tag(self::TAG)
            ->data([
                'url' => '/notifications',
                'unreadCount' => (new CountUnreadNotifications)($notifiable),
            ]);

        $preview = NotificationPreview::for($kind, $this->data, $notifiable);

        return $preview === null ? $message : $message->body($preview);
    }

    private function actorName(): ?string
    {
        return $this->actorId === null ? null : MemberDisplayName::of(Member::find($this->actorId));
    }
}
