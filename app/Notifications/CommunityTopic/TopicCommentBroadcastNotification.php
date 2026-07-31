<?php

namespace App\Notifications\CommunityTopic;

use App\Mail\Template\MailTemplate;
use App\Models\Community;
use App\Models\CommunityTopic;
use App\Models\CommunityTopicComment;
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
 * The community-wide side of a topic comment (CommentNewPost): reaches members who are neither the
 * topic author nor a co-commenter — they get Reply / Related instead (TopicCommentedNotification). The
 * fan-out pre-resolves each recipient's channels, so via() returns them verbatim. Same feed kind and
 * mail template as the author/co-commenter notification, distinguished by the Community reason.
 */
class TopicCommentBroadcastNotification extends Notification implements FeatureNotification, ShouldQueue
{
    use GatedByFeature;
    use Queueable;
    use RendersMailTemplate;

    /** @param list<string> $channels the pre-resolved delivery channels (mail and/or database). */
    public function __construct(
        public readonly Community $community,
        public readonly CommunityTopic $topic,
        public readonly CommunityTopicComment $comment,
        public readonly Member $commenter,
        public readonly array $channels,
    ) {}

    public static function feature(): Feature
    {
        return Feature::CommunityTopic;
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
            'topic_name' => $this->topic->name,
            'nickname' => $this->commenter->name,
            'body' => $this->comment->body,
            'url' => route('communityTopic.show', ['topic' => $this->topic->getKey()]),
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'community_topic_commented',
            'reason' => CommentReason::Community->value,
            'commenter_id' => $this->commenter->getKey(),
            'topic_id' => $this->topic->getKey(),
            'comment_id' => $this->comment->getKey(),
        ];
    }
}
