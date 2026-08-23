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
 * Tells a member a talk message @mentions them. Mail + database, gated by the recipient's catalog
 * kind. It is the notification every site sends: the per-message broadcast beside it
 * (GroupTalkMessagePostedNotification) fires only where an administrator asked for one, and this one
 * pierces the mute that stops it.
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

    /**
     * SerializesModels hands this fresh rows, so the eligibility answer is delivery-time current.
     *
     * The feed row additionally waits on the reader's cursor, as the room's broadcast does: a row
     * written for a message they have already read is a bell over nothing. Mail is unaffected — a
     * mention is worth telling someone about whether or not they were looking.
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
            // The conversation, opened on the message that named them: talk has no screen for one
            // message, so the link is a place in the conversation. A message deleted before the
            // reader follows it lands them on the newest page instead.
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
