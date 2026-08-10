<?php

namespace App\Notifications\Timeline;

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

/**
 * Tells a member a new post or reply @mentions them. Mail + database, gated by the recipient's
 * catalog kind.
 */
class TimelineMentionedNotification extends Notification implements FeatureNotification, ShouldQueue
{
    use GatedByFeature;
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

    /** @return list<string> */
    public function via(Member $notifiable): array
    {
        return $this->templateChannelsFor(MailTemplate::TimelineMentionNotified, NotificationKind::TimelineMention, $notifiable, ['database']);
    }

    public function toMail(Member $notifiable): MailMessage
    {
        return $this->mailFromTemplate(MailTemplate::TimelineMentionNotified, [
            'member_name' => $this->author->name,
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
