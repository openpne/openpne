<?php

namespace App\Notifications\CommunityEvent;

use App\Mail\Template\MailTemplate;
use App\Models\CommunityEvent;
use App\Models\CommunityEventComment;
use App\Models\Member;
use App\Notifications\CommentReason;
use App\Notifications\Concerns\RendersMailTemplate;
use App\Notifications\Settings\NotificationKind;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the event author (Reply) or a co-commenter (Related) a new comment landed. Mail +
 * database, gated by the recipient's catalog kind for the reason. Shares the community-posting
 * template with topics, so the event title binds the template's topic_name variable.
 */
class EventCommentedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RendersMailTemplate;

    public function __construct(
        public readonly Member $commenter,
        public readonly CommunityEvent $event,
        public readonly CommunityEventComment $comment,
        public readonly CommentReason $reason,
    ) {}

    /** @return list<string> */
    public function via(Member $notifiable): array
    {
        $kind = $this->reason === CommentReason::Reply
            ? NotificationKind::CommunityEventReplyNewPost
            : NotificationKind::CommunityEventRelatedNewPost;

        return $this->templateChannelsFor(MailTemplate::CommunityPostingNotified, $kind, $notifiable, ['database']);
    }

    public function toMail(Member $notifiable): MailMessage
    {
        return $this->mailFromTemplate(MailTemplate::CommunityPostingNotified, [
            'community_name' => $this->event->community->name,
            'topic_name' => $this->event->name,
            'nickname' => $this->commenter->name,
            'body' => $this->comment->body,
            'url' => route('communityEvent.show', ['event' => $this->event->getKey()]),
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(Member $notifiable): array
    {
        return [
            'kind' => 'community_event_commented',
            'reason' => $this->reason->value,
            'commenter_id' => $this->commenter->getKey(),
            'event_id' => $this->event->getKey(),
            'comment_id' => $this->comment->getKey(),
        ];
    }
}
