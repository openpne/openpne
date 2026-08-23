<?php

namespace App\Notifications\GroupTalk;

use App\Features\GroupTalk\GroupTalkNotificationEligibility;
use App\Features\Member\MemberDisplayName;
use App\Mail\Template\MailTemplate;
use App\Models\GroupMessage;
use App\Models\Member;
use App\Notifications\Concerns\GatedByFeature;
use App\Notifications\Concerns\RendersMailTemplate;
use App\Notifications\FeatureNotification;
use App\Support\Feature;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a member a talk room has something new in it, on a site whose administrator asked for that
 * (docs/internals/group-talk.md). The fan-out job resolves each recipient's channels once — from the
 * kind's site default and the explicit rows overriding it — and passes the decided list, so `via()`
 * returns it verbatim.
 *
 * Its feed row is the ROOM's row, not the message's: KeepOneGroupTalkRoomRow drops the room's other
 * rows once this one is written, so the feed holds one line per conversation however much is said in it.
 */
class GroupTalkMessagePostedNotification extends Notification implements FeatureNotification, ShouldQueue
{
    use GatedByFeature {
        shouldSend as private featureShouldSend;
    }
    use Queueable;
    use RendersMailTemplate;

    /** An author who withdrew before delivery takes the notification with them, as a mention does. */
    public bool $deleteWhenMissingModels = true;

    /** @param list<string> $channels the pre-resolved delivery channels (mail and/or database). */
    public function __construct(
        public readonly Member $author,
        public readonly GroupMessage $message,
        public readonly array $channels,
    ) {}

    public static function feature(): Feature
    {
        return Feature::GroupTalk;
    }

    /**
     * SerializesModels hands this fresh rows, so the answer is delivery-time current: a ban, a fresh
     * block, a member who left or muted the room since — and a member who has since read the message,
     * which is the whole point of the grace the dispatch waits out.
     */
    public function shouldSend(Member $notifiable, string $channel): bool
    {
        return $this->featureShouldSend($notifiable, $channel)
            && GroupTalkNotificationEligibility::canReceiveBroadcast($notifiable, $this->message, $this->author);
    }

    /** @return list<string> */
    public function via(Member $notifiable): array
    {
        return $this->channels;
    }

    public function toMail(Member $notifiable): MailMessage
    {
        return $this->mailFromTemplate(MailTemplate::GroupTalkMessageNotified, [
            'member_name' => MemberDisplayName::of($this->author),
            'community_name' => $this->message->group?->name ?? '',
            'body' => $this->message->body,
            // The same anchor a mention mail carries: talk has no screen for one message, so the link
            // is a place in the conversation.
            'url' => route('group.talk.show', ['group' => $this->message->group_id, 'm' => $this->message->getKey()]),
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(Member $notifiable): array
    {
        return [
            'kind' => 'group_talk_new_message',
            'author_id' => $this->author->getKey(),
            // The group is what the row is about — the feed opens the room, not the message — but the
            // message id stays so the row says which one it was written for.
            'group_id' => $this->message->group_id,
            'message_id' => $this->message->getKey(),
        ];
    }
}
