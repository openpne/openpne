<?php

namespace App\Notifications\Member;

use App\Mail\Template\MailTemplate;
use App\Notifications\Concerns\RendersMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the member's current address before `members.email` changes, so the address it reaches is still
 * theirs. Its cancel link voids the pending change only; it never itself alters the login identifier.
 */
class EmailChangeNoticeNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RendersMailTemplate;

    public function __construct(
        public readonly string $newEmail,
        public readonly string $rawCancelToken,
        string $locale,
    ) {
        $this->locale($locale);
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->mailFromTemplate(MailTemplate::EmailChangeNotice, [
            'new_email' => $this->newEmail,
            'cancel_url' => route('member.config.email.cancel', ['token' => $this->rawCancelToken]),
        ]);
    }
}
