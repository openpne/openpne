<?php

namespace App\Notifications\Member;

use App\Mail\Template\MailTemplate;
use App\Notifications\Concerns\RendersMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A notify-only security alert, sent on-demand to the member's CURRENT (old) address when an email
 * change is requested — the takeover-detection control (OWASP). Sent before members.email changes, so
 * the address it reaches is still the member's own. It carries a cancel link (a second single-use
 * token) so the old-address holder can void a change they did not initiate without signing in; the
 * link only voids the pending change, it never itself alters the login identifier.
 */
class EmailChangeNoticeNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RendersMailTemplate;

    public function __construct(
        public readonly string $newEmail,
        public readonly string $rawCancelToken,
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
        return $this->mailFromTemplate(MailTemplate::EmailChangeNotice, [
            'new_email' => $this->newEmail,
            'cancel_url' => route('member.config.email.cancel', ['token' => $this->rawCancelToken]),
        ]);
    }
}
