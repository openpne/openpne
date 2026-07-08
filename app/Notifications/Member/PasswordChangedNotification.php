<?php

namespace App\Notifications\Member;

use App\Mail\Template\MailTemplate;
use App\Notifications\Concerns\RendersMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A notify-only security alert sent after the member's password changes (self-service change or a
 * forgot-password reset) — the takeover-detection control. Mail only, always sent, no action link;
 * the actionable path for an unexpected change is "reset your password", not a one-click revert.
 */
class PasswordChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RendersMailTemplate;

    public function __construct(string $locale)
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
        return $this->mailFromTemplate(MailTemplate::PasswordChanged);
    }
}
