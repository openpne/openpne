<?php

namespace App\Notifications\Member;

use App\Mail\Template\MailTemplate;
use App\Notifications\Concerns\RendersMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The admin-issued "reset your two-factor authentication" mail, sent on-demand to the member's registered
 * address (so the notifiable is an AnonymousNotifiable pinned to that literal address, never the Member —
 * matching RequestEmailChange's queue-safety reasoning). Carries the raw token in the reset link only; the
 * locale is captured at request time because the notification renders later on the queue.
 */
class MfaResetLinkNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RendersMailTemplate;

    public function __construct(
        public readonly string $rawToken,
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
        return $this->mailFromTemplate(MailTemplate::MfaResetLink, [
            'url' => route('member.mfa.reset', ['token' => $this->rawToken]),
        ]);
    }
}
