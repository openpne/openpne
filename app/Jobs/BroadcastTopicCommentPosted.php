<?php

namespace App\Jobs;

use App\Features\Group\GroupNewPostFanout;
use App\Features\Group\Queries\GroupNewPostRecipients;
use App\Mail\Template\MailTemplate;
use App\Mail\Template\MailTemplateService;
use App\Models\CommunityTopic;
use App\Models\CommunityTopicComment;
use App\Models\Member;
use App\Notifications\CommunityTopic\TopicCommentBroadcastNotification;
use App\Notifications\Settings\NotificationKind;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fans a topic comment out to the community's other members off the request (CommentNewPost). The
 * audience excludes the topic author and everyone who already commented — they get the Reply / Related
 * notification instead — so precedence is Reply > Related > Group with one notification per member.
 */
class BroadcastTopicCommentPosted implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @param list<int> $excludedMemberIds the author + co-commenters, snapshotted at dispatch time */
    public function __construct(
        public readonly int $topicId,
        public readonly int $commentId,
        public readonly int $commenterId,
        public readonly array $excludedMemberIds,
    ) {}

    public function handle(GroupNewPostFanout $fanout, GroupNewPostRecipients $recipients, MailTemplateService $templates): void
    {
        // Saves the audience walk only; the send gate itself is the notification's shouldSend().
        if (! TopicCommentBroadcastNotification::feature()->enabled()) {
            return;
        }

        $topic = CommunityTopic::with('community')->find($this->topicId);
        $comment = CommunityTopicComment::find($this->commentId);
        $commenter = Member::find($this->commenterId);
        if ($topic === null || $topic->community === null || $comment === null || $commenter === null) {
            return;
        }

        // The author + co-commenters (who get Reply / Related) are excluded using the set captured when
        // the comment was posted, not re-read here: a comment deleted before this job ran would otherwise
        // drop its author out of the exclusion and double-notify them (Related then Group).
        $audience = $recipients->viewers($topic->community, $commenter)->whereNotIn('id', $this->excludedMemberIds);
        $mailEnabled = $templates->isEnabled(MailTemplate::CommunityPostingNotified);

        $fanout->run(
            $audience,
            NotificationKind::CommunityTopicCommentNewPost,
            $mailEnabled,
            fn (array $channels) => new TopicCommentBroadcastNotification($topic->community, $topic, $comment, $commenter, $channels),
        );
    }
}
