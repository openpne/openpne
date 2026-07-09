<?php

namespace App\Jobs;

use App\Features\Community\CommunityNewPostFanout;
use App\Features\Community\Queries\CommunityNewPostRecipients;
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
 * notification instead — so precedence is Reply > Related > Community with one notification per member.
 */
class BroadcastTopicCommentPosted implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $topicId,
        public readonly int $commentId,
        public readonly int $commenterId,
    ) {}

    public function handle(CommunityNewPostFanout $fanout, CommunityNewPostRecipients $recipients, MailTemplateService $templates): void
    {
        $topic = CommunityTopic::with('community')->find($this->topicId);
        $comment = CommunityTopicComment::find($this->commentId);
        $commenter = Member::find($this->commenterId);
        if ($topic === null || $topic->community === null || $comment === null || $commenter === null) {
            return;
        }

        // Everyone who commented (co-commenters) plus the author get Reply / Related; keep them out of
        // the broadcast so no member is notified twice.
        $excluded = CommunityTopicComment::query()
            ->where('community_topic_id', $topic->getKey())
            ->whereNotNull('member_id')
            ->distinct()
            ->pluck('member_id')
            ->all();
        if ($topic->member_id !== null) {
            $excluded[] = $topic->member_id;
        }

        $audience = $recipients->viewers($topic->community, $commenter)->whereNotIn('id', $excluded);
        $mailEnabled = $templates->isEnabled(MailTemplate::CommunityPostingNotified);

        $fanout->run(
            $audience,
            NotificationKind::CommunityTopicCommentNewPost,
            $mailEnabled,
            fn (array $channels) => new TopicCommentBroadcastNotification($topic->community, $topic, $comment, $commenter, $channels),
        );
    }
}
