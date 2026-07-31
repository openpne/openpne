<?php

namespace App\Jobs;

use App\Features\Community\CommunityNewPostFanout;
use App\Features\Community\Queries\CommunityNewPostRecipients;
use App\Mail\Template\MailTemplate;
use App\Mail\Template\MailTemplateService;
use App\Models\CommunityEvent;
use App\Notifications\CommunityEvent\EventPostedNotification;
use App\Notifications\Settings\NotificationKind;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Fans a new event out to its community's members off the request (see CommunityNewPostFanout). */
class BroadcastEventPosted implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $eventId) {}

    public function handle(CommunityNewPostFanout $fanout, CommunityNewPostRecipients $recipients, MailTemplateService $templates): void
    {
        // Saves the audience walk only; the send gate itself is the notification's shouldSend().
        if (! EventPostedNotification::feature()->enabled()) {
            return;
        }

        $event = CommunityEvent::with('community', 'member')->find($this->eventId);
        if ($event === null || $event->community === null || $event->member === null) {
            return;
        }

        $community = $event->community;
        $author = $event->member;
        $mailEnabled = $templates->isEnabled(MailTemplate::CommunityPostingNotified);

        $fanout->run(
            $recipients->viewers($community, $author),
            NotificationKind::CommunityEventNewPost,
            $mailEnabled,
            fn (array $channels) => new EventPostedNotification($community, $event, $author, $channels),
        );
    }
}
