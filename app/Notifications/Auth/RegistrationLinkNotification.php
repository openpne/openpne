<?php

namespace App\Notifications\Auth;

use App\Features\Auth\RegistrationTokenSource;
use App\Mail\Template\MailTemplate;
use App\Notifications\Concerns\RendersMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The "complete your registration" mail, sent to an address with no Member yet (on-demand, so the
 * notifiable is an AnonymousNotifiable). Carries the raw token in the link only; the locale is captured
 * at request time because the notification renders later on the queue. The optional inviter name /
 * personal message are shown by the template's conditional blocks (OpenPNE 3 requestRegisterURL), so the
 * self / member-invite / admin-invite variants share one template and never drift.
 */
class RegistrationLinkNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RendersMailTemplate;

    public function __construct(
        public readonly string $rawToken,
        string $locale,
        public readonly RegistrationTokenSource $source = RegistrationTokenSource::Selfservice,
        public readonly ?string $inviterName = null,
        public readonly ?string $message = null,
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
        return $this->mailFromTemplate(MailTemplate::RegistrationLink, [
            'name' => $this->inviterName,
            'message' => $this->message,
            'token' => $this->rawToken,
            'authMode' => 'MailAddress',
        ]);
    }
}
