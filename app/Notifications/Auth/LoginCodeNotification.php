<?php

namespace App\Notifications\Auth;

use App\Mail\Template\MailTemplate;
use App\Notifications\Concerns\RendersMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Encrypted on the queue, so the code never sits in `jobs` or `failed_jobs` in the clear. */
class LoginCodeNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;
    use RendersMailTemplate;

    public function __construct(
        public readonly string $code,
        public readonly int $minutes,
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
        return $this->mailFromTemplate(MailTemplate::LoginCode, [
            'code' => $this->code,
            'minutes' => $this->minutes,
        ]);
    }
}
