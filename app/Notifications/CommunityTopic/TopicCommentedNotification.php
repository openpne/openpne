<?php

namespace App\Notifications\CommunityTopic;

use App\Mail\Template\MailTemplate;
use App\Models\CommunityTopic;
use App\Models\CommunityTopicComment;
use App\Models\Member;
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
 * Tells the topic author (Reply) or a co-commenter (Related) a new comment landed. Mail +
 * database, gated by the recipient's catalog kind for the reason.
 */
class TopicCommentedNotification extends Notification implements FeatureNotification, ShouldQueue
{
    use GatedByFeature;
    use Queueable;
    use RendersMailTemplate;

    public function __construct(
        public readonly Member $commenter,
        public readonly CommunityTopic $topic,
        public readonly CommunityTopicComment $comment,
        public readonly CommentReason $reason,
    ) {}

    public static function feature(): Feature
    {
        return Feature::CommunityTopic;
    }

    /** @return list<string> */
    public function via(Member $notifiable): array
    {
        $kind = $this->reason === CommentReason::Reply
            ? NotificationKind::CommunityTopicReplyNewPost
            : NotificationKind::CommunityTopicRelatedNewPost;

        return $this->templateChannelsFor(MailTemplate::CommunityPostingNotified, $kind, $notifiable, ['database']);
    }

    public function toMail(Member $notifiable): MailMessage
    {
        return $this->mailFromTemplate(MailTemplate::CommunityPostingNotified, [
            'community_name' => $this->topic->community->name,
            'topic_name' => $this->topic->name,
            'nickname' => $this->commenter->name,
            'body' => $this->comment->body,
            'url' => route('communityTopic.show', ['topic' => $this->topic->getKey()]),
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(Member $notifiable): array
    {
        return [
            'kind' => 'community_topic_commented',
            'reason' => $this->reason->value,
            'commenter_id' => $this->commenter->getKey(),
            'topic_id' => $this->topic->getKey(),
            'comment_id' => $this->comment->getKey(),
        ];
    }
}
