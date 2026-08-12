<?php

namespace App\Jobs;

use App\Features\Community\CommunityNewPostFanout;
use App\Features\Community\Queries\CommunityNewPostRecipients;
use App\Mail\Template\MailTemplate;
use App\Mail\Template\MailTemplateService;
use App\Models\TimelinePost;
use App\Notifications\Settings\NotificationKind;
use App\Notifications\Timeline\TimelineCommunityPostedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fans a new community timeline post out to that community's members, through the same fan-out the
 * topic and event broadcasts use — the audience is the community, not the item.
 *
 * The SNS-wide BroadcastTimelinePosted is not also dispatched for these posts. Its audience is the
 * visibility ladder, which an everyone-readable community would resolve to every member: a post
 * would arrive twice, under two kinds, and the opt-out a member reached for would be the wrong one.
 * NotifyTimelinePosted picks one or the other, never both.
 */
class BroadcastCommunityTimelinePosted implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @param list<int> $mentionedMemberIds the members the post named, snapshotted at dispatch time */
    public function __construct(
        public readonly int $postId,
        public readonly array $mentionedMemberIds = [],
    ) {}

    public function handle(CommunityNewPostFanout $fanout, CommunityNewPostRecipients $recipients, MailTemplateService $templates): void
    {
        // Saves the audience walk only; the send gate itself is the notification's shouldSend().
        if (! TimelineCommunityPostedNotification::feature()->enabled()) {
            return;
        }

        $post = TimelinePost::with('member', 'community')->find($this->postId);
        // Deleted before the job ran, or its author withdrew / its community was removed.
        if ($post === null || $post->member === null || $post->community === null) {
            return;
        }

        $author = $post->member;

        // Precedence Mention > NewPost: subtracts the very set the mention notification was sent to
        // (the event's snapshot), which is what makes it one notification per member.
        $audience = $recipients->viewers($post->community, $author)
            ->whereNotIn('id', $this->mentionedMemberIds);

        $fanout->run(
            $audience,
            NotificationKind::TimelineNewPostCommunity,
            $templates->isEnabled(MailTemplate::TimelinePostingNotified),
            fn (array $channels) => new TimelineCommunityPostedNotification($post, $author, $channels),
        );
    }
}
