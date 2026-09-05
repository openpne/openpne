<?php

namespace App\Features\DirectMessage;

enum DirectMessageBox: string
{
    case Receive = 'receive';
    case Sent = 'sent';
    case Draft = 'draft';
    case Trash = 'trash';

    public function listRoute(): string
    {
        return match ($this) {
            self::Receive => 'message.receive',
            self::Sent => 'message.send',
            self::Draft => 'message.draft',
            self::Trash => 'message.trash',
        };
    }

    /** A draft has no show page, so the draft box opens its edit form as OpenPNE 3 did. */
    public function openRoute(): string
    {
        return match ($this) {
            self::Receive => 'message.receive.show',
            self::Sent => 'message.send.show',
            self::Trash => 'message.trash.show',
            self::Draft => 'message.draft.edit',
        };
    }

    public function heading(): string
    {
        return match ($this) {
            self::Receive => __('Inbox'),
            self::Sent => __('Sent Message'),
            self::Draft => __('Drafts'),
            self::Trash => __('Trash'),
        };
    }

    public function counterpartyHeading(): string
    {
        return match ($this) {
            self::Receive => __('Sender'),
            self::Sent, self::Draft => __('Recipient'),
            self::Trash => __('Sender/Recipient'),
        };
    }
}
