<?php

namespace App\Features\Message;

/**
 * The four message boxes (OpenPNE 3 list `type`: receive/send/draft/dust). Centralises each box's
 * route names and labels so the controller, queries, list view, and sidemenu agree.
 */
enum MessageBox: string
{
    case Receive = 'receive';
    case Sent = 'sent';
    case Draft = 'draft';
    case Trash = 'trash';

    /** The list route (OpenPNE 3 @receiveList / @sendList / @draftList / @dustList). */
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
     * The per-message show route, or null for Draft (OpenPNE 3 opens a draft in the compose/edit
     * form, which lands with the write surface — until then a draft row has no show page).
     */
    public function showRoute(): ?string
    {
        return match ($this) {
            self::Receive => 'message.receive.show',
            self::Sent => 'message.send.show',
            self::Trash => 'message.trash.show',
            self::Draft => null,
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
