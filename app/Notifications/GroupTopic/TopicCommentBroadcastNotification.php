<?php

namespace App\Notifications\GroupTopic;

use App\Mail\Template\MailTemplate;
use App\Models\Group;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
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
 * The group-wide side of a topic comment (CommentNewPost): reaches members who are neither the
 * topic author nor a co-commenter — they get Reply / Related instead (TopicCommentedNotification). The
 * fan-out pre-resolves each recipient's channels, so via() returns them verbatim. Same feed kind and
 * mail template as the author/co-commenter notification, distinguished by the Group reason.
 */
class TopicCommentBroadcastNotification extends Notification implements FeatureNotification, ShouldQueue
{
    use GatedByFeature;
    use Queueable;
    use RendersMailTemplate;

    /** @param list<string> $channels the pre-resolved delivery channels (mail and/or database). */
    public function __construct(
        public readonly Group $group,
        public readonly GroupTopic $topic,
        public readonly GroupTopicComment $comment,
        public readonly Member $commenter,
        public readonly array $channels,
    ) {}

    public static function feature(): Feature
    {
        return Feature::GroupTopic;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->mailFromTemplate(MailTemplate::GroupPostingNotified, [
            'community_name' => $this->group->name,
            'topic_name' => $this->topic->name,
            'nickname' => $this->commenter->name,
            'body' => $this->comment->body,
            'url' => route('group.topics.show', ['topic' => $this->topic->getKey()]),
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'group_topic_commented',
            'reason' => CommentReason::Group->value,
            'commenter_id' => $this->commenter->getKey(),
            'topic_id' => $this->topic->getKey(),
            'comment_id' => $this->comment->getKey(),
        ];
    }
}
