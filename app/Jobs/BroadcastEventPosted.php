<?php

namespace App\Jobs;

use App\Features\Group\GroupNewPostFanout;
use App\Features\Group\Queries\GroupNewPostRecipients;
use App\Mail\Template\MailTemplate;
use App\Mail\Template\MailTemplateService;
use App\Models\GroupEvent;
use App\Notifications\GroupEvent\EventPostedNotification;
use App\Notifications\Settings\NotificationKind;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Fans a new event out to its community's members off the request (see GroupNewPostFanout). */
class BroadcastEventPosted implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $eventId) {}

    public function handle(GroupNewPostFanout $fanout, GroupNewPostRecipients $recipients, MailTemplateService $templates): void
    {
        // Saves the audience walk only; the send gate itself is the notification's shouldSend().
        if (! EventPostedNotification::feature()->enabled()) {
            return;
        }

        $event = GroupEvent::with('group', 'member')->find($this->eventId);
        if ($event === null || $event->group === null || $event->member === null) {
            return;
        }

        $group = $event->group;
        $author = $event->member;
        $mailEnabled = $templates->isEnabled(MailTemplate::GroupPostingNotified);

        $fanout->run(
            $recipients->viewers($group, $author),
            NotificationKind::GroupEventNewPost,
            $mailEnabled,
            fn (array $channels) => new EventPostedNotification($group, $event, $author, $channels),
        );
    }
}
