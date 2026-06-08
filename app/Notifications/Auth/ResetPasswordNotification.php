<?php

namespace App\Notifications\Auth;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The Laravel/Fortify password-reset mail, but queued. A synchronous SMTP send for known addresses
 * only would make the forgot-password endpoint measurably slower for registered addresses — a timing
 * oracle that survives the neutral response message. Queuing collapses that signal to a cheap job
 * dispatch on both paths. Locale is captured at request time because the mail renders on the queue.
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
