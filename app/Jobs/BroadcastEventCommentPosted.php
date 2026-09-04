<?php

namespace App\Jobs;

use App\Features\Group\GroupNewPostFanout;
use App\Features\Group\Queries\GroupNewPostRecipients;
use App\Mail\Template\MailTemplate;
use App\Mail\Template\MailTemplateService;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\Member;
use App\Notifications\GroupEvent\EventCommentBroadcastNotification;
use App\Notifications\Settings\NotificationKind;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fans an event comment out to the community's other members off the request (CommentNewPost). The
 * audience excludes the event author and everyone who already commented — they get the Reply / Related
 * notification instead — so precedence is Reply > Related > Group with one notification per member.
 */
class BroadcastEventCommentPosted implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @param list<int> $excludedMemberIds the author + co-commenters, snapshotted at dispatch time */
    public function __construct(
        public readonly int $eventId,
        public readonly int $commentId,
        public readonly int $commenterId,
        public readonly array $excludedMemberIds,
    ) {}

    public function handle(GroupNewPostFanout $fanout, GroupNewPostRecipients $recipients, MailTemplateService $templates): void
    {
        // Saves the audience walk only; the send gate itself is the notification's shouldSend().
        if (! EventCommentBroadcastNotification::feature()->enabled()) {
            return;
        }

        $event = GroupEvent::with('group')->find($this->eventId);
        $comment = GroupEventComment::find($this->commentId);
        $commenter = Member::find($this->commenterId);
        if ($event === null || $event->group === null || $comment === null || $commenter === null) {
            return;
        }

        // The exclusion is the set captured when the comment was posted, never one re-read here: a
        // comment deleted since would drop its own author out of it.
        $audience = $recipients->viewers($event->group, $commenter)->whereNotIn('id', $this->excludedMemberIds);
        $mailEnabled = $templates->isEnabled(MailTemplate::GroupPostingNotified);

        $fanout->run(
            $audience,
            NotificationKind::GroupEventCommentNewPost,
            $mailEnabled,
            fn (array $channels) => new EventCommentBroadcastNotification($event->group, $event, $comment, $commenter, $channels),
        );
    }
}
