<?php

namespace App\Notifications\GroupEvent;

use App\Mail\Template\MailTemplate;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
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
 * Tells the event author (Reply) or a co-commenter (Related) a new comment landed. Mail +
 * database, gated by the recipient's catalog kind for the reason. Shares the group-posting
 * template with topics, so the event title binds the template's topic_name variable.
 */
class EventCommentedNotification extends Notification implements FeatureNotification, ShouldQueue
{
    use GatedByFeature;
    use Queueable;
    use RendersMailTemplate;

    public function __construct(
        public readonly Member $commenter,
        public readonly GroupEvent $event,
        public readonly GroupEventComment $comment,
        public readonly CommentReason $reason,
    ) {}

    public static function feature(): Feature
    {
        return Feature::GroupEvent;
    }

    /** @return list<string> */
    public function via(Member $notifiable): array
    {
        $kind = $this->reason === CommentReason::Reply
            ? NotificationKind::GroupEventReplyNewPost
            : NotificationKind::GroupEventRelatedNewPost;

        return $this->templateChannelsFor(MailTemplate::GroupPostingNotified, $kind, $notifiable, ['database']);
    }

    public function toMail(Member $notifiable): MailMessage
    {
        return $this->mailFromTemplate(MailTemplate::GroupPostingNotified, [
            'community_name' => $this->event->group->name,
            'topic_name' => $this->event->name,
            'nickname' => $this->commenter->name,
            'body' => $this->comment->body,
            'url' => route('group.events.show', ['event' => $this->event->getKey()]),
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(Member $notifiable): array
    {
        return [
            'kind' => 'group_event_commented',
            'reason' => $this->reason->value,
            'commenter_id' => $this->commenter->getKey(),
            'event_id' => $this->event->getKey(),
            'comment_id' => $this->comment->getKey(),
        ];
    }
}
