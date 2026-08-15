<?php

namespace App\Notifications\Timeline;

use App\Features\Member\MemberDisplayName;
use App\Features\Timeline\TimelineNotificationEligibility;
use App\Mail\Template\MailTemplate;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Notifications\CommentReason;
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
 * Tells the thread root's author (Reply) or another member who replied to it (Related) a new reply
 * landed. Mail + database, gated by the recipient's catalog kind for the reason.
 */
class TimelineRepliedNotification extends Notification implements FeatureNotification, ShouldQueue
{
    use GatedByFeature {
        shouldSend as private featureShouldSend;
    }
    use Queueable;
    use RendersMailTemplate;

    public function __construct(
        public readonly Member $replier,
        public readonly TimelinePost $reply,
        public readonly CommentReason $reason,
    ) {}

    public static function feature(): Feature
    {
        return Feature::Timeline;
    }

    /** SerializesModels hands this fresh rows, so the eligibility answer is delivery-time current. */
    public function shouldSend(Member $notifiable, string $channel): bool
    {
        return $this->featureShouldSend($notifiable, $channel)
            && TimelineNotificationEligibility::canReceive($notifiable, $this->reply, $this->replier);
    }

    /** @return list<string> */
    public function via(Member $notifiable): array
    {
        $kind = $this->reason === CommentReason::Reply
            ? NotificationKind::TimelineReplyPost
            : NotificationKind::TimelineRelatedPost;

        return $this->templateChannelsFor(MailTemplate::TimelinePostingNotified, $kind, $notifiable, ['database']);
    }

    public function toMail(Member $notifiable): MailMessage
    {
        return $this->mailFromTemplate(MailTemplate::TimelinePostingNotified, [
            'member_name' => MemberDisplayName::of($this->replier),
            'author' => MemberDisplayName::of($this->replier),
            'body' => $this->reply->body,
            'url' => route('timeline.show', ['timelinePost' => $this->threadRootId()]),
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(Member $notifiable): array
    {
        return [
            'kind' => 'timeline_replied',
            'reason' => $this->reason->value,
            'replier_id' => $this->replier->getKey(),
            // The reply itself, not its thread: the feed resolves the root when it builds the link.
            'post_id' => $this->reply->getKey(),
        ];
    }

    /** A thread has one address — the top-level post's permalink (see TimelineController::show). */
    private function threadRootId(): int
    {
        return (int) ($this->reply->in_reply_to_id ?? $this->reply->getKey());
    }
}
