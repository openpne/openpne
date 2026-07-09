<?php

namespace App\Jobs;

use App\Features\Community\CommunityNewPostFanout;
use App\Features\Community\Queries\CommunityNewPostRecipients;
use App\Mail\Template\MailTemplate;
use App\Mail\Template\MailTemplateService;
use App\Models\CommunityEvent;
use App\Models\CommunityEventComment;
use App\Models\Member;
use App\Notifications\CommunityEvent\EventCommentBroadcastNotification;
use App\Notifications\Settings\NotificationKind;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fans an event comment out to the community's other members off the request (CommentNewPost). The
 * audience excludes the event author and everyone who already commented — they get the Reply / Related
 * notification instead — so precedence is Reply > Related > Community with one notification per member.
 */
class BroadcastEventCommentPosted implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $eventId,
        public readonly int $commentId,
        public readonly int $commenterId,
    ) {}

    public function handle(CommunityNewPostFanout $fanout, CommunityNewPostRecipients $recipients, MailTemplateService $templates): void
    {
        $event = CommunityEvent::with('community')->find($this->eventId);
        $comment = CommunityEventComment::find($this->commentId);
        $commenter = Member::find($this->commenterId);
        if ($event === null || $event->community === null || $comment === null || $commenter === null) {
            return;
        }

        $excluded = CommunityEventComment::query()
            ->where('community_event_id', $event->getKey())
            ->whereNotNull('member_id')
            ->distinct()
            ->pluck('member_id')
            ->all();
        if ($event->member_id !== null) {
            $excluded[] = $event->member_id;
        }

        $audience = $recipients->viewers($event->community, $commenter)->whereNotIn('id', $excluded);
        $mailEnabled = $templates->isEnabled(MailTemplate::CommunityPostingNotified);

        $fanout->run(
            $audience,
            NotificationKind::CommunityEventCommentNewPost,
            $mailEnabled,
            fn (array $channels) => new EventCommentBroadcastNotification($event->community, $event, $comment, $commenter, $channels),
        );
    }
}
