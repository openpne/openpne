<?php

namespace App\Compat\Parities;

use App\Compat\CompatLevel as L;
use App\Compat\RouteMap;
use App\Compat\RouteParity;
use App\Compat\ScreenElement;
use App\Compat\ScreenStatus as S;

class MessageRouteParity extends RouteParity
{
    protected string $module = 'message';

    public function maps(): array
    {
        return [
            // The four boxes all render OpenPNE 3's message/list action (body id page_message_list).
            new RouteMap('receiveList', '/message/receiveList', 'message.receive', 'GET', op3Action: 'list'),
            new RouteMap('sendList', '/message/sendList', 'message.send', 'GET', op3Action: 'list'),
            new RouteMap('draftList', '/message/draftList', 'message.draft', 'GET', op3Action: 'list'),
            new RouteMap('dustList', '/message/dustList', 'message.trash', 'GET', op3Action: 'list'),
            // Per-box show (message/show, body id page_message_show), box in the path as OpenPNE 3.
            new RouteMap('readReceiveMessage', '/message/read/:id', 'message.receive.show', 'GET', op3Action: 'show'),
            new RouteMap('readSendMessage', '/message/check/:id', 'message.send.show', 'GET', op3Action: 'show'),
            new RouteMap('readDustMessage', '/message/checkDelete/:id', 'message.trash.show', 'GET', op3Action: 'show'),
        ];
    }

    public function gaps(): array
    {
        return [
            // Delete / restore / purge (trash) — the write surface, lands in the next PR.
            'deleteReceiveMessage' => 'Move a received message to trash; write surface (next PR).',
            'deleteSendMessage' => 'Move a sent message to trash; write surface (next PR).',
            'deleteDustMessage' => 'Purge a message from trash; write surface (next PR).',
            'deleteConfirmDustMessage' => 'Purge confirmation; write surface (next PR).',
            // Smartphone-only thread view; OpenPNE 4 has no mobile surface.
            'messageChain' => 'Smartphone-only message thread; OpenPNE 4 has no mobile surface.',
            // JSON message API (compose / search / recent) — not ported (Phase 2+).
            'message_post' => 'JSON compose API; not ported (Phase 2+).',
            'message_search' => 'JSON conversation search API; not ported (Phase 2+).',
            'recent_message_list' => 'JSON recent-messages API; not ported (Phase 2+).',
        ];
    }

    /**
     * OpenPNE 3 keeps compose/reply/edit/restore reachable through the module/action fallback (they
     * have no named route), so the named routes are not the complete reachable set.
     */
    public function acknowledgesGlobalFallback(): bool
    {
        return true;
    }

    /**
     * Surface elements per OpenPNE 3 message template, against resources/views/message/*.blade.php.
     */
    public function screens(): array
    {
        return [
            // listSuccess.php (all four boxes) → message/list.blade.php
            'list' => [
                new ScreenElement('box nav sidemenu (Inbox/Sent/Drafts/Trash)', L::Two, S::Ported, "include_partial('message/sidemenu')", 'x-message.sidemenu; current box not linked'),
                new ScreenElement('per-box heading + counterparty column (From/To)', L::Two, S::Ported, '$title / $sender_title switch'),
                new ScreenElement('status icon (unread / read / sent / draft)', L::Two, S::Partial, 'icon_mail_* by box + is_read', 'unread row + status class hooks; OpenPNE 3 gif icons not shipped with the basic skin'),
                new ScreenElement('replied icon', L::Three, S::Missing, 'getIsHensin() icon_mail_4', 'replied state pairs with reply (write surface)'),
                new ScreenElement('subject link to show', L::One, S::Ported, 'link_to($detail_title, $detail_url)', 'draft links to the edit form (write surface), so its subject is plain text here'),
                new ScreenElement('created-at datetime', L::Three, S::Ported, "format_datetime(created_at, 'f')", 'LocalizedDate'),
                new ScreenElement('pager navigation', L::Two, S::Ported, 'op_include_pager_navigation'),
                new ScreenElement('empty-state message', L::Three, S::Ported, "__('There are no messages')"),
                new ScreenElement('bulk delete / restore form + check-all', L::Two, S::Missing, 'MessageDeleteForm checkboxes', 'write surface (next PR)'),
            ],
            // showSuccess.php → message/show.blade.php
            'show' => [
                new ScreenElement('box nav sidemenu', L::Two, S::Ported, "include_partial('message/sidemenu')", 'x-message.sidemenu'),
                new ScreenElement('previous / next links within box', L::Two, S::Ported, 'getPrevious/getNext($type, $myMemberId)', 'adjacent by id within the box'),
                new ScreenElement('From / To members', L::One, S::Ported, '$fromOrToMembers (getIsSender)'),
                new ScreenElement('counterparty thumbnail', L::Two, S::Deferred, 'image_tag_sf_image 76x76', 'avatar delivery exists; wired with the write/profile-link pass'),
                new ScreenElement('subject + created-at', L::One, S::Ported, '$message->getSubject() / format_datetime'),
                new ScreenElement('body line breaks + auto-link', L::Two, S::Ported, 'auto_link_text(nl2br(getDecoratedMessageBody))', 'x-user-text (BodyText); <op:*> decoration not rendered'),
                new ScreenElement('attachment images', L::Three, S::Deferred, '$message->getMessageFile()', 'attachments land with the write surface (next PR)'),
                new ScreenElement('reply / delete / restore buttons', L::Two, S::Missing, 'operation buttons', 'write surface (next PR)'),
            ],
        ];
    }
}
