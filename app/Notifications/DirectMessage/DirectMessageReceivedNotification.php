<?php

namespace App\Notifications\DirectMessage;

use App\Features\Block\BlockLookup;
use App\Mail\Template\MailTemplate;
use App\Models\DirectMessage;
use App\Models\Member;
use App\Notifications\Concerns\GatedByFeature;
use App\Notifications\Concerns\RendersMailTemplate;
use App\Notifications\FeatureNotification;
use App\Notifications\Settings\NotificationChannel;
use App\Notifications\Settings\NotificationKind;
use App\Support\Feature;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a recipient a new message arrived. Mail + database, each gated by the recipient's catalog
 * opt-in: DirectMessageNew covers every sender; while it is off, DirectMessageNewOnlyFriends (its
 * dependOnNot variant) still covers friend senders. This exact chain is what imported opt-outs
 * were saved against, so it must not change shape.
 */
class DirectMessageReceivedNotification extends Notification implements FeatureNotification, ShouldQueue
{
    use GatedByFeature {
        shouldSend as private featureShouldSend;
    }
    use Queueable;
    use RendersMailTemplate;

    /**
     * A sender who withdrew between the send and its delivery takes the notification with them, and
     * so does a purged message: the queued job cannot restore either model, and "Withdrawn member
     * sent you a message" announces something nobody can open.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly Member $sender,
        public readonly DirectMessage $message,
    ) {}

    public static function feature(): Feature
    {
        return Feature::DirectMessage;
    }

    /**
     * The delivery-time re-check (docs/internals/notifications.md#delivery-time-re-checks). The mail
     * carries the body, so every fact that decides whether the recipient may read this message is
     * asked again here — SerializesModels hands the job fresh rows, so the answer is current.
     */
    public function shouldSend(Member $notifiable, string $channel): bool
    {
        return $this->featureShouldSend($notifiable, $channel)
            && ! $notifiable->is_login_rejected
            && ! BlockLookup::hasAnyBlockBetween($notifiable, $this->sender)
            && $this->stillTheirs($notifiable);
    }

    /** @return list<string> */
    public function via(Member $notifiable): array
    {
        $channels = $this->recipientWants($notifiable, NotificationChannel::Mail)
            ? $this->templateChannels(MailTemplate::DirectMessageReceived)
            : [];

        if ($this->recipientWants($notifiable, NotificationChannel::Web)) {
            $channels[] = 'database';
        }

        return $channels;
    }

    public function toMail(Member $notifiable): MailMessage
    {
        return $this->mailFromTemplate(MailTemplate::DirectMessageReceived, [
            'member' => ['name' => $this->sender->name],
            // The extension wording's flat variable names, so an imported body renders as-is.
            'member_name' => $this->sender->name,
            'message_subject' => $this->message->subject,
            'message_body' => $this->message->body,
            'url' => route('message.receive.show', ['message' => $this->message->getKey()]),
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(Member $notifiable): array
    {
        return [
            'kind' => 'direct_message_received',
            'sender_id' => $this->sender->getKey(),
            'direct_message_id' => $this->message->getKey(),
        ];
    }

    /**
     * Whether this message is still the recipient's to read: a receipt of theirs that has not been
     * purged. Purge is the one side-state that revokes reading (trash does not — a trashed message is
     * restorable and still theirs), so a purged message must not be carried out by mail.
     */
    private function stillTheirs(Member $notifiable): bool
    {
        return $this->message->recipients()
            ->where('recipient_id', $notifiable->getKey())
            ->whereNull('recipient_purged_at')
            ->exists();
    }

    private function recipientWants(Member $notifiable, NotificationChannel $channel): bool
    {
        if ($notifiable->wantsNotification(NotificationKind::DirectMessageNew, $channel)) {
            return true;
        }

        return $notifiable->isFriendsWith($this->sender)
            && $notifiable->wantsNotification(NotificationKind::DirectMessageNewOnlyFriends, $channel);
    }
}
