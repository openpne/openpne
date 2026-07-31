<?php

namespace App\Notifications\CommunityEvent;

use App\Mail\Template\MailTemplate;
use App\Models\Community;
use App\Models\CommunityEvent;
use App\Models\CommunityEventComment;
use App\Models\Member;
use App\Notifications\CommentReason;
use App\Notifications\Concerns\GatedByFeature;
use App\Notifications\Concerns\RendersMailTemplate;
use App\Notifications\FeatureNotification;
use App\Support\Feature;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The community-wide side of an event comment (CommentNewPost): reaches members who are neither the
 * event author nor a co-commenter — they get Reply / Related instead (EventCommentedNotification). The
 * fan-out pre-resolves each recipient's channels, so via() returns them verbatim. Same feed kind and
 * mail template as the author/co-commenter notification, distinguished by the Community reason.
 */
class EventCommentBroadcastNotification extends Notification implements FeatureNotification, ShouldQueue
{
    use GatedByFeature;
    use Queueable;
    use RendersMailTemplate;

    /** @param list<string> $channels the pre-resolved delivery channels (mail and/or database). */
    public function __construct(
        public readonly Community $community,
        public readonly CommunityEvent $event,
        public readonly CommunityEventComment $comment,
        public readonly Member $commenter,
        public readonly array $channels,
    ) {}

    public static function feature(): Feature
    {
        return Feature::CommunityEvent;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->mailFromTemplate(MailTemplate::CommunityPostingNotified, [
            'community_name' => $this->community->name,
            'topic_name' => $this->event->name,
            'nickname' => $this->commenter->name,
            'body' => $this->comment->body,
            'url' => route('communityEvent.show', ['event' => $this->event->getKey()]),
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'community_event_commented',
            'reason' => CommentReason::Community->value,
            'commenter_id' => $this->commenter->getKey(),
            'event_id' => $this->event->getKey(),
            'comment_id' => $this->comment->getKey(),
        ];
    }
}
