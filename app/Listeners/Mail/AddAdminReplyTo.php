<?php

declare(strict_types=1);

namespace App\Listeners\Mail;

use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;

/**
 * Runs below every producer (notification, mailable, raw), so no mail class has to opt in
 * (docs/internals/notifications.md, "Mail headers").
 */
final class AddAdminReplyTo
{
    public function handle(MessageSending $event): void
    {
        $email = $event->message;
        if ($email->getReplyTo() !== []) {
            return;
        }

        $admin = sns_admin_mail_address();
        if ($admin === '') {
            return;
        }

        foreach ($email->getFrom() as $from) {
            if (strcasecmp($from->getAddress(), $admin) === 0) {
                return;
            }
        }

        $email->replyTo(new Address($admin, sns_name()));
    }
}
