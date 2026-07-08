<?php

namespace App\Notifications\Member;

use App\Mail\Template\MailTemplate;
use App\Notifications\Concerns\RendersMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The "your leaving process was finished" mail (OpenPNE 3 leave), sent to the just-withdrawn member's
 * captured address as an on-demand notifiable (Notification::route) — the Member row is already gone.
 * Mail only and always sent. The name and locale travel as scalars, so nothing dereferences the row.
 */
class WithdrawalCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RendersMailTemplate;

    public function __construct(public readonly string $memberName, string $locale)
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
        return $this->mailFromTemplate(MailTemplate::WithdrawalCompleted, [
            'member' => ['name' => $this->memberName],
        ]);
    }
}
