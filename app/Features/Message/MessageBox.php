<?php

namespace App\Features\Message;

/**
 * The four message boxes. Centralises each box's route names and labels so the controller,
 * queries, list view, and sidemenu agree.
 */
enum MessageBox: string
{
    case Receive = 'receive';
    case Sent = 'sent';
    case Draft = 'draft';
    case Trash = 'trash';

    /** The list route. */
    public function listRoute(): string
    {
        return match ($this) {
            self::Receive => 'message.receive',
            self::Sent => 'message.send',
            self::Draft => 'message.draft',
            self::Trash => 'message.trash',
        };
    }

    /**
     * The route that opens a message from this box: the show page, or the edit form for a
     * draft (OpenPNE 3 also opens a draft in the compose form — a draft has no show page).
     */
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

    /** The counterparty column header: Sender (inbox), Recipient (sent/draft), Sender/Recipient (trash mixes sides). */
    public function counterpartyHeading(): string
    {
        return match ($this) {
            self::Receive => __('Sender'),
            self::Sent, self::Draft => __('Recipient'),
            self::Trash => __('Sender/Recipient'),
        };
    }
}
