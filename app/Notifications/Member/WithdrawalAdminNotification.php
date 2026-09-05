<?php

namespace App\Notifications\Member;

use App\Mail\Template\MailTemplate;
use App\Notifications\Concerns\RendersMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Rendered in the site default locale: it is operator-facing, not addressed to the withdrawing member.
 */
class WithdrawalAdminNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RendersMailTemplate;

    public function __construct(
        public readonly string $memberName,
        public readonly string $memberEmail,
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
        return $this->mailFromTemplate(MailTemplate::WithdrawalAdminNotice, [
            'member' => [
                'name' => $this->memberName,
                'email' => $this->memberEmail,
                'id' => $this->memberId,
            ],
        ]);
    }
}
