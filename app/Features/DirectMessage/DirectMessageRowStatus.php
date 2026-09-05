<?php

namespace App\Features\DirectMessage;

/**
 * The state icon on a message list row (OpenPNE 3 listSuccess.php, icon_mail_1..4). The inbox
 * reports the receipt's own state; every other box reports which box the row came from, which is
 * what OpenPNE 3's trash rows label (PluginDeletedMessage::getIcon / getIconAlt) — so one icon
 * serves two meanings and the label disambiguates it.
 */
enum DirectMessageRowStatus
{
    case Unopened;
    case Opened;
    case Replied;
    case Sent;
    case Draft;
    case Received;

    /** File name under public/opMessagePlugin/images/. */
    public function icon(): string
    {
        return match ($this) {
            self::Unopened, self::Draft => 'icon_mail_1.gif',
            self::Opened, self::Received => 'icon_mail_2.gif',
            self::Sent => 'icon_mail_3.gif',
            self::Replied => 'icon_mail_4.gif',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Unopened => __('Unread'),
            self::Opened => __('Read (adjective)'),
            self::Replied => __('Replied'),
            self::Sent => __('Sent Message'),
            self::Draft => __('Drafts'),
            self::Received => __('Inbox'),
        };
    }
}
