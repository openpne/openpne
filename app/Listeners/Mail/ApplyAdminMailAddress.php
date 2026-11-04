<?php

declare(strict_types=1);

namespace App\Listeners\Mail;

use App\Support\MailFromAddress;
use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;

/**
 * Runs below every producer (notification, mailable, raw), so no mail class has to opt in
 * (docs/internals/notifications.md, "Mail headers").
 */
final class ApplyAdminMailAddress
{
    public function handle(MessageSending $event): void
    {
        $email = $event->message;
        $admin = sns_admin_mail_address();
        if ($admin === '') {
            return;
        }

        $from = $email->getFrom();
        $first = $from[0] ?? null;
        if (! MailFromAddress::isConfigured($first?->getAddress() ?? '')) {
            $email->from(new Address($admin, $first?->getName() ?: sns_name()));

            return;
        }

        if ($email->getReplyTo() !== []) {
            return;
        }

        foreach ($from as $address) {
            if (strcasecmp($address->getAddress(), $admin) === 0) {
                return;
            }
        }

        $email->replyTo(new Address($admin, sns_name()));
    }
}
