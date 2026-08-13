<?php

namespace App\Notifications\Timeline;

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
 * Announces a new post in a community's timeline. Separate from TimelinePostedNotification because
 * the two answer to different opt-outs — a member silences their groups' timelines without
 * silencing the SNS-wide one — and a feed row that said "posted to the timeline" would send the
 * reader looking in the wrong place.
 *
 * The unit declared is Timeline, not Group: with the Group unit off the post stops being
 * viewable at all, which the eligibility check already reads.
 *
 * The mail reuses the timeline-posting template. A community post is still a timeline post, and
 * OpenPNE 3 registered this kind without ever sending it, so there is no wording of its own to
 * carry over — and no reason to add another admin-editable template for it.
 */
class TimelineCommunityPostedNotification extends Notification implements FeatureNotification, ShouldQueue
{
    use GatedByFeature {
        shouldSend as private featureShouldSend;
    }
    use Queueable;
    use RendersMailTemplate;

    /** @param list<string> $channels the pre-resolved delivery channels (mail and/or database). */
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
            'member_name' => $this->author->name,
            'author' => $this->author->name,
            'body' => $this->post->body,
            'url' => route('timeline.show', ['timelinePost' => $this->post->getKey()]),
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(Member $notifiable): array
    {
        return [
            'kind' => 'timeline_posted_community',
            'author_id' => $this->author->getKey(),
            'post_id' => $this->post->getKey(),
            'community_id' => $this->post->community_id,
        ];
    }
}
