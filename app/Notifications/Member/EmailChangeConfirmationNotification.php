<?php

namespace App\Notifications\Member;

use App\Mail\Template\MailTemplate;
use App\Notifications\Concerns\RendersMailTemplate;
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
    use RendersMailTemplate;

    public function __construct(
        public readonly string $rawToken,
        public readonly int $memberId,
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
        // id/type feed the OpenPNE 3 confirm URL params and stay available as body variables (the
        // OpenPNE 3 changeMailAddress variable contract); the OpenPNE 4 URL keeps only the token.
        return $this->mailFromTemplate(MailTemplate::EmailChangeConfirm, [
            'token' => $this->rawToken,
            'id' => $this->memberId,
            'type' => 'pc_address',
        ]);
    }
}
