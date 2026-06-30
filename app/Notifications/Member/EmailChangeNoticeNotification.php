<?php

namespace App\Notifications\Member;

use App\Mail\Template\MailTemplate;
use App\Notifications\Concerns\RendersMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A notify-only security alert, sent on-demand to the member's CURRENT (old) address when an email
 * change is requested — the takeover-detection control (OWASP). It carries no action link: the
 * request step already re-authenticated with the current password, so the actionable path for an
 * unexpected change is "change your password", not a one-click revert. Sent before members.email
 * changes, so the address it reaches is still the member's own.
 */
class EmailChangeNoticeNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RendersMailTemplate;

    public function __construct(public readonly string $newEmail, string $locale)
    {
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
        ]);
    }
}
