<?php

namespace App\Jobs;

use App\Features\Group\GroupNewPostFanout;
use App\Features\Group\Queries\GroupNewPostRecipients;
use App\Mail\Template\MailTemplate;
use App\Mail\Template\MailTemplateService;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\Member;
use App\Notifications\GroupTopic\TopicCommentBroadcastNotification;
use App\Notifications\Settings\NotificationKind;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fans a topic comment out to the group's other members off the request (CommentNewPost). The
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

        $topic = GroupTopic::with('group')->find($this->topicId);
        $comment = GroupTopicComment::find($this->commentId);
        $commenter = Member::find($this->commenterId);
        if ($topic === null || $topic->group === null || $comment === null || $commenter === null) {
            return;
        }

        // The exclusion is the set captured when the comment was posted, never one re-read here: a
        // comment deleted since would drop its own author out of it.
        $audience = $recipients->viewers($topic->group, $commenter)->whereNotIn('id', $this->excludedMemberIds);
        $mailEnabled = $templates->isEnabled(MailTemplate::GroupPostingNotified);

        $fanout->run(
            $audience,
            NotificationKind::GroupTopicCommentNewPost,
            $mailEnabled,
            fn (array $channels) => new TopicCommentBroadcastNotification($topic->group, $topic, $comment, $commenter, $channels),
        );
    }
}
