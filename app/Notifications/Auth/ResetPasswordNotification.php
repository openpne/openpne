<?php

namespace App\Notifications\Auth;

use App\Mail\Template\MailTemplate;
use App\Notifications\Concerns\RendersMailTemplate;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Queued because a synchronous SMTP send happens only for a known address, making forgot-password
 * measurably slower for registered ones — a timing oracle the neutral response message survives. Removing
 * that signal relies on a non-sync queue in production; a residual token/job DB write on the known path
 * remains.
 */
class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;
    use RendersMailTemplate;

    public function __construct(string $token, string $locale)
    {
        parent::__construct($token);
        $this->locale($locale);
    }

    public function toMail($notifiable): MailMessage
    {
        return $this->mailFromTemplate(MailTemplate::PasswordReset, [
            'url' => $this->resetUrl($notifiable),
        ]);
    }
}
