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
use App\Support\Feature;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The fan-out resolves each recipient's channels once and passes them, so via() returns them
 * verbatim and gates nothing (docs/internals/notifications.md, "Broadcast fan-out").
 */
class TimelinePostedNotification extends Notification implements FeatureNotification, ShouldQueue
{
    use GatedByFeature {
        shouldSend as private featureShouldSend;
    }
    use Queueable;
    use RendersMailTemplate;

    /** @param list<string> $channels */
    public function __construct(
        public readonly TimelinePost $post,
        public readonly Member $author,
        public readonly array $channels,
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
        return $this->channels;
    }

    public function toMail(Member $notifiable): MailMessage
    {
        return $this->mailFromTemplate(MailTemplate::TimelinePostingNotified, [
            'member_name' => MemberDisplayName::of($this->author),
            'author' => MemberDisplayName::of($this->author),
            'body' => $this->post->body,
            'url' => route('timeline.show', ['timelinePost' => $this->post->getKey()]),
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(Member $notifiable): array
    {
        return [
            'kind' => 'timeline_posted',
            'author_id' => $this->author->getKey(),
            'post_id' => $this->post->getKey(),
        ];
    }
}
