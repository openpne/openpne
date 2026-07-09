<?php

namespace App\Jobs;

use App\Features\Community\CommunityNewPostFanout;
use App\Features\Community\Queries\CommunityNewPostRecipients;
use App\Mail\Template\MailTemplate;
use App\Mail\Template\MailTemplateService;
use App\Models\CommunityTopic;
use App\Notifications\CommunityTopic\TopicPostedNotification;
use App\Notifications\Settings\NotificationKind;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Fans a new topic out to its community's members off the request (see CommunityNewPostFanout). */
class BroadcastTopicPosted implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $topicId) {}

    public function handle(CommunityNewPostFanout $fanout, CommunityNewPostRecipients $recipients, MailTemplateService $templates): void
    {
        $topic = CommunityTopic::with('community', 'member')->find($this->topicId);
        if ($topic === null || $topic->community === null || $topic->member === null) {
            return;
        }

        $community = $topic->community;
        $author = $topic->member;
        $mailEnabled = $templates->isEnabled(MailTemplate::CommunityPostingNotified);

        $fanout->run(
            $recipients->viewers($community, $author),
            NotificationKind::CommunityTopicNewPost,
            $mailEnabled,
            fn (array $channels) => new TopicPostedNotification($community, $topic, $author, $channels),
        );
    }
}
