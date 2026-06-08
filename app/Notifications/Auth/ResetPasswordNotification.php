<?php

namespace App\Notifications\Auth;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The Laravel/Fortify password-reset mail, but queued: a synchronous SMTP send happens only for a
 * known address, making forgot-password measurably slower for registered ones — a timing oracle that
 * survives the neutral response message. Queuing removes that dominant (SMTP) signal and relies on a
 * non-sync queue in production; a small residual token/job DB write on the known path remains. Locale
 * is captured at request time because the mail renders on the queue.
 */
class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;

    public function __construct(string $token, string $locale)
    {
        parent::__construct($token);
        $this->locale($locale);
    }
}
