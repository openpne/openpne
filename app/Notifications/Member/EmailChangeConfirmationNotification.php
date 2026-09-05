<?php

namespace App\Notifications\Member;

use App\Mail\Template\MailTemplate;
use App\Notifications\Concerns\RendersMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent on-demand to the proposed new address, so the notifiable is an AnonymousNotifiable rather than the
 * Member, whose address is still the old one.
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
