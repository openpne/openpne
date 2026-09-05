<?php

namespace App\Notifications\GroupTalk;

use App\Features\GroupTalk\GroupTalkNotificationEligibility;
use App\Features\GroupTalk\TalkReadCursor;
use App\Features\Member\MemberDisplayName;
use App\Mail\Template\MailTemplate;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\Member;
use App\Notifications\Concerns\GatedByFeature;
use App\Notifications\Concerns\RendersMailTemplate;
use App\Notifications\FeatureNotification;
use App\Notifications\Settings\NotificationKind;
use App\Support\Feature;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent by every site, and it pierces the mute that stops the per-message broadcast beside it
 * (docs/internals/group-talk.md, What talk notifies).
 */
class GroupTalkMentionedNotification extends Notification implements FeatureNotification, ShouldQueue
{
    use GatedByFeature {
        shouldSend as private featureShouldSend;
    }
    use Queueable;
    use RendersMailTemplate;

    /**
     * An author who withdrew between the mention and its delivery takes the notification with them: the
     * queued job cannot restore the serialized Member.
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

    /**
     * SerializesModels hands this fresh rows, so the eligibility answer is delivery-time current; the feed
     * row additionally waits on the reader's cursor (docs/internals/notifications.md, Delivery-time re-checks).
     */
    public function shouldSend(Member $notifiable, string $channel): bool
    {
        return $this->featureShouldSend($notifiable, $channel)
            && GroupTalkNotificationEligibility::canReceive($notifiable, $this->group(), $this->author)
            && ($channel !== 'database' || $this->stillUnread($notifiable));
    }

    private function stillUnread(Member $notifiable): bool
    {
        return TalkReadCursor::isBehind(
            (int) $this->message->group_id,
            (int) $notifiable->getKey(),
            CarbonImmutable::instance($this->message->created_at),
            (int) $this->message->getKey(),
        );
    }

    /** @return list<string> */
    public function via(Member $notifiable): array
    {
        return $this->templateChannelsFor(MailTemplate::GroupTalkMentionNotified, NotificationKind::GroupTalkMention, $notifiable, ['database']);
    }

    public function toMail(Member $notifiable): MailMessage
    {
        return $this->mailFromTemplate(MailTemplate::GroupTalkMentionNotified, [
            'member_name' => MemberDisplayName::of($this->author),
            'community_name' => $this->group()->name,
            'body' => $this->message->body,
            // Talk has no per-message screen, so the link is a place in the conversation; a message
            // deleted before the reader follows it lands them on the newest page.
            'url' => route('group.talk.show', ['group' => $this->message->group_id, 'm' => $this->message->getKey()]),
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
