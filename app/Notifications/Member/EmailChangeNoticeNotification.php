<?php

namespace App\Notifications\Member;

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
        return (new MailMessage)
            ->from(sns_admin_mail_address(), sns_name())
            ->subject(__('Your email address change was requested'))
            ->line(__('A request was made to change your :app email address to :email.', ['app' => sns_name(), 'email' => $this->newEmail]))
            ->line(__('If this was you, open the confirmation link sent to the new address to finish.'))
            ->line(__('If this was not you, change your password immediately or contact the administrator.'))
            ->salutation('— '.sns_name());
    }
}
