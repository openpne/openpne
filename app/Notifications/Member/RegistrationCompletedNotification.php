<?php

namespace App\Notifications\Member;

use App\Mail\Template\MailTemplate;
use App\Notifications\Concerns\RendersMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The "your account is ready" mail (OpenPNE 3 registerEnd), sent once a member completes registration.
 * Mail only and always sent — a required transactional mail with no admin toggle and no member opt-out.
 * The locale is captured at dispatch because the notification renders later on the queue.
 */
class RegistrationCompletedNotification extends Notification implements ShouldQueue
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
        return $this->mailFromTemplate(MailTemplate::RegistrationCompleted, [
            // The home URL, as OpenPNE 3 registerEnd passed (genUrl homepage), not the login page.
            'url' => route('home'),
        ]);
    }
}
