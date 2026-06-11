<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The "complete your registration" mail, sent to an address with no Member yet (on-demand, so the
 * notifiable is an AnonymousNotifiable). Carries the raw token in the link only; the locale is
 * captured at request time because the notification renders later on the queue.
 */
class RegistrationLinkNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $rawToken, string $locale)
    {
        // The base Notification's $locale (applied when the mail renders on the queue); not a
        // promoted property, which would clash with that base property.
        $this->locale($locale);
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $hours = (int) ceil(config('openpne.registration.token_ttl_minutes') / 60);

        // register.form (GET /register/{token}) is added in the next PR; build the URL directly so
        // this mail does not depend on that route existing yet.
        return (new MailMessage)
            ->from(sns_admin_mail_address(), sns_name())
            ->subject(__('Complete your :app registration', ['app' => sns_name()]))
            ->line(__('Open the link below to finish creating your account.'))
            ->action(__('Continue registration'), url('/register/'.$this->rawToken))
            ->line(__('This link expires in :hours hours.', ['hours' => $hours]))
            ->salutation('— '.sns_name());
    }
}
