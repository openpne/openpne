<?php

namespace App\Notifications\Timeline;

use App\Features\Member\MemberDisplayName;
use App\Features\Timeline\TimelineNotificationEligibility;
use App\Mail\Template\MailTemplate;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Notifications\Concerns\GatedByFeature;
use App\Notifications\Concerns\RendersMailTemplate;
use App\Notifications\FeatureNotification;
use App\Notifications\Settings\NotificationKind;
use App\Support\Feature;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TimelineMentionedNotification extends Notification implements FeatureNotification, ShouldQueue
{
    use GatedByFeature {
        shouldSend as private featureShouldSend;
    }
    use Queueable;
    use RendersMailTemplate;

    public function __construct(
        public readonly Member $author,
        public readonly TimelinePost $post,
    ) {}

    public static function feature(): Feature
    {
        return Feature::Timeline;
    }

    /** SerializesModels hands this fresh rows, so the eligibility answer is delivery-time current. */
    public function shouldSend(Member $notifiable, string $channel): bool
    {
        return $this->featureShouldSend($notifiable, $channel)
            && TimelineNotificationEligibility::canReceive($notifiable, $this->post, $this->author);
    }

    /** @return list<string> */
    public function via(Member $notifiable): array
    {
        return $this->templateChannelsFor(MailTemplate::TimelineMentionNotified, NotificationKind::TimelineMention, $notifiable, ['database']);
    }

    public function toMail(Member $notifiable): MailMessage
    {
        return $this->mailFromTemplate(MailTemplate::TimelineMentionNotified, [
            'member_name' => MemberDisplayName::of($this->author),
            'body' => $this->post->body,
            'url' => route('timeline.show', ['timelinePost' => $this->threadRootId()]),
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(Member $notifiable): array
    {
        return [
            'kind' => 'timeline_mentioned',
            'author_id' => $this->author->getKey(),
            // The mentioning post, not its thread: the feed resolves the root when it builds the
            // link, so a row keeps pointing at what was actually written.
            'post_id' => $this->post->getKey(),
        ];
    }

    /** A thread has one address — the top-level post's permalink (see TimelineController::show). */
    private function threadRootId(): int
    {
        return (int) ($this->post->in_reply_to_id ?? $this->post->getKey());
    }
}
