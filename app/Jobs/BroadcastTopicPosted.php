<?php

namespace App\Jobs;

use App\Features\Group\GroupNewPostFanout;
use App\Features\Group\Queries\GroupNewPostRecipients;
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

/** Fans a new topic out to its community's members off the request (see GroupNewPostFanout). */
class BroadcastTopicPosted implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $topicId) {}

    public function handle(GroupNewPostFanout $fanout, GroupNewPostRecipients $recipients, MailTemplateService $templates): void
    {
        // Saves the audience walk only; the send gate itself is the notification's shouldSend().
        if (! TopicPostedNotification::feature()->enabled()) {
            return;
        }

        $topic = CommunityTopic::with('community', 'member')->find($this->topicId);
        if ($topic === null || $topic->community === null || $topic->member === null) {
            return;
        }

        $group = $topic->community;
        $author = $topic->member;
        $mailEnabled = $templates->isEnabled(MailTemplate::CommunityPostingNotified);

        $fanout->run(
            $recipients->viewers($group, $author),
            NotificationKind::CommunityTopicNewPost,
            $mailEnabled,
            fn (array $channels) => new TopicPostedNotification($group, $topic, $author, $channels),
        );
    }
}
