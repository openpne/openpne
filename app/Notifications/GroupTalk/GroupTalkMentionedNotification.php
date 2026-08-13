<?php

namespace App\Notifications\GroupTalk;

use App\Features\GroupTalk\GroupTalkNotificationEligibility;
use App\Mail\Template\MailTemplate;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\Member;
use App\Notifications\Concerns\GatedByFeature;
use App\Notifications\Concerns\RendersMailTemplate;
use App\Notifications\FeatureNotification;
use App\Notifications\Settings\NotificationKind;
use App\Support\Feature;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a member a talk message @mentions them. Mail + database, gated by the recipient's catalog
 * kind — the only notification talk sends. There is deliberately no per-message broadcast: a chat
 * that notified on every line would empty the feed of meaning, so the room's unread badge carries
 * that job instead.
 */
class GroupTalkMentionedNotification extends Notification implements FeatureNotification, ShouldQueue
{
    use GatedByFeature {
        shouldSend as private featureShouldSend;
    }
    use Queueable;
    use RendersMailTemplate;

    /**
     * An author who withdrew between the mention and its delivery takes the notification with them:
     * the queued job cannot restore the serialized Member and is discarded silently. Delivering it
     * as "Withdrawn member mentioned you" would be a notification nobody can act on, about someone
     * who no longer exists.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly Member $author,
        public readonly GroupMessage $message,
    ) {}

    public static function feature(): Feature
    {
        return Feature::GroupTalk;
    }

    /** SerializesModels hands this fresh rows, so the eligibility answer is delivery-time current. */
    public function shouldSend(Member $notifiable, string $channel): bool
    {
        return $this->featureShouldSend($notifiable, $channel)
            && GroupTalkNotificationEligibility::canReceive($notifiable, $this->group(), $this->author);
    }

    /** @return list<string> */
    public function via(Member $notifiable): array
    {
        return $this->templateChannelsFor(MailTemplate::GroupTalkMentionNotified, NotificationKind::GroupTalkMention, $notifiable, ['database']);
    }

    public function toMail(Member $notifiable): MailMessage
    {
        return $this->mailFromTemplate(MailTemplate::GroupTalkMentionNotified, [
            'member_name' => $this->author->name,
            'community_name' => $this->group()->name,
            'body' => $this->message->body,
            // The conversation, not the message: talk has no per-message permalink, and inventing
            // one would be inventing a screen.
            'url' => route('group.talk.show', ['group' => $this->message->group_id]),
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(Member $notifiable): array
    {
        return [
            'kind' => 'group_talk_mention',
            'author_id' => $this->author->getKey(),
            // Both ids: the feed re-checks the message still exists and the group still admits the
            // reader, and carrying the group saves hydrating it through the message at click time.
            'group_id' => $this->message->group_id,
            'message_id' => $this->message->getKey(),
        ];
    }

    private function group(): Group
    {
        return $this->message->group;
    }
}
