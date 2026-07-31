<?php

namespace App\Notifications\Diary;

use App\Mail\Template\MailTemplate;
use App\Models\Diary;
use App\Models\DiaryComment;
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
 * Tells the diary owner (Reply) or a co-commenter (Related) a new comment landed. Mail +
 * database, gated by the recipient's catalog kind for the reason.
 */
class DiaryCommentedNotification extends Notification implements FeatureNotification, ShouldQueue
{
    use GatedByFeature;
    use Queueable;
    use RendersMailTemplate;

    public function __construct(
        public readonly Member $commenter,
        public readonly Diary $diary,
        public readonly DiaryComment $comment,
        public readonly CommentReason $reason,
    ) {}

    public static function feature(): Feature
    {
        return Feature::Diary;
    }

    /** @return list<string> */
    public function via(Member $notifiable): array
    {
        $kind = $this->reason === CommentReason::Reply
            ? NotificationKind::DiaryReplyPost
            : NotificationKind::DiaryRelatedPost;

        return $this->templateChannelsFor(MailTemplate::DiaryCommentReceived, $kind, $notifiable, ['database']);
    }

    public function toMail(Member $notifiable): MailMessage
    {
        return $this->mailFromTemplate(MailTemplate::DiaryCommentReceived, [
            'member_name' => $this->commenter->name,
            'diary_title' => $this->diary->title,
            'body' => $this->comment->body,
            'url' => route('diary.show', ['diary' => $this->diary->getKey()]),
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(Member $notifiable): array
    {
        return [
            'kind' => 'diary_commented',
            'reason' => $this->reason->value,
            'commenter_id' => $this->commenter->getKey(),
            'diary_id' => $this->diary->getKey(),
            'comment_id' => $this->comment->getKey(),
        ];
    }
}
