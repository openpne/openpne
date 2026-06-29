<?php

namespace App\Notifications\Member;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The "confirm your new email address" mail, sent on-demand to the proposed NEW address (so the
 * notifiable is an AnonymousNotifiable, never the Member — its address is the still-current one).
 * Carries the raw token in the confirmation link only; the locale is captured at request time
 * because the notification renders later on the queue.
 */
class EmailChangeConfirmationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $rawToken, string $locale)
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
        $hours = (int) ceil(config('openpne.email_change.token_ttl_minutes') / 60);

        return (new MailMessage)
            ->from(sns_admin_mail_address(), sns_name())
            ->subject(__('Confirm your new email address'))
            ->line(__('Open the link below to confirm this address as your new :app email address.', ['app' => sns_name()]))
            ->action(__('Confirm email change'), url('/member/config/email/confirm/'.$this->rawToken))
            ->line(__('This link expires in :hours hours.', ['hours' => $hours]))
            ->line(__('If you did not request this, you can ignore this email.'))
            ->salutation('— '.sns_name());
    }
}
