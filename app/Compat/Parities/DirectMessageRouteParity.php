<?php

namespace App\Compat\Parities;

use App\Compat\CompatLevel as L;
use App\Compat\RouteMap;
use App\Compat\RouteParity;
use App\Compat\ScreenElement;
use App\Compat\ScreenStatus as S;

class DirectMessageRouteParity extends RouteParity
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
            // Compose / reply / draft edit. OpenPNE 3 reached these via the module/action fallback
            // (no named route), so they bind to no inventory entry but still derive a body id.
            new RouteMap(null, null, 'message.compose', 'GET', op3Action: 'sendToFriend'),
            new RouteMap(null, null, 'message.compose.store', 'POST'),
            new RouteMap(null, null, 'message.reply', 'GET', op3Action: 'reply'),
            new RouteMap(null, null, 'message.draft.edit', 'GET', op3Action: 'edit'),
            new RouteMap(null, null, 'message.draft.update', 'POST'),
            // Trash. The move-to-trash and purge submits are CSRF form posts (POST in the inventory),
            // not bookmarkable URLs; the single purge has a GET confirm page. Restore and the bulk
            // list action have no named OpenPNE 3 route (module/action fallback), so they bind to no
            // inventory entry. The bulk route derives the list body id for its confirmation page.
            new RouteMap('deleteReceiveMessage', '/message/deleteReceiveMessage/:id', 'message.receive.trash', 'POST'),
            new RouteMap('deleteSendMessage', '/message/deleteSendMessage/:id', 'message.send.trash', 'POST'),
            new RouteMap('deleteConfirmDustMessage', '/message/deleteConfirm/:id', 'message.trash.purge.confirm', 'GET', op3Action: 'deleteConfirm'),
            new RouteMap('deleteDustMessage', '/message/deleteComplete/:id', 'message.trash.purge', 'POST'),
            new RouteMap(null, null, 'message.trash.restore', 'POST'),
            new RouteMap(null, null, 'message.bulk', 'POST', op3Action: 'list'),
        ];
    }

    protected function layouts(): array
    {
        // OpenPNE 3 message module is layoutB (its view.yml `all:`): the four box lists and the
        // per-box show carry the message sidemenu. The compose/reply/draft-edit forms and the
        // delete-confirm pages keep layoutC (decorate_with on the confirms, no sidemenu).
        return [
            'message.receive' => 'B',
            'message.send' => 'B',
            'message.draft' => 'B',
            'message.trash' => 'B',
            'message.receive.show' => 'B',
            'message.send.show' => 'B',
            'message.trash.show' => 'B',
        ];
    }

    public function gaps(): array
    {
        return [
            // Smartphone-only thread view; OpenPNE 4 has no mobile surface.
            'messageChain' => 'Smartphone-only message thread; OpenPNE 4 has no mobile surface.',
            // JSON message API (compose / search / recent) — not ported.
            'message_post' => 'JSON compose API; not ported.',
            'message_search' => 'JSON conversation search API; not ported.',
            'recent_message_list' => 'JSON recent-messages API; not ported.',
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
                new ScreenElement('status icon (unread / read / sent / draft)', L::Two, S::Ported, 'icon_mail_* by box + is_read', 'DirectMessageRowStatus picks the vendored opMessagePlugin/images/icon_mail_1..4.gif per row'),
                new ScreenElement('replied icon', L::Three, S::Ported, 'getIsHensin() icon_mail_4', 'ListDirectMessages::repliedTo → DirectMessageRowStatus::Replied'),
                new ScreenElement('subject link to show', L::One, S::Ported, 'link_to($detail_title, $detail_url)', 'the draft box links the subject to the edit form'),
                new ScreenElement('created-at datetime', L::Three, S::Ported, "format_datetime(created_at, 'f')", 'LocalizedDate'),
                new ScreenElement('pager navigation', L::Two, S::Ported, 'op_include_pager_navigation'),
                new ScreenElement('empty-state message', L::Three, S::Ported, "__('There are no messages')"),
                new ScreenElement('bulk delete / restore form + check-all', L::Two, S::Ported, 'MessageDeleteForm checkboxes', 'trash from receive/send/draft, restore/purge from trash; purge confirms first'),
                // Sent box (@sendList).
                new ScreenElement('sent box: own sends, newest first', L::Two, S::Ported, 'SendMessageDataTable::getSendMessagePager (is_send, is_deleted, created_at DESC)'),
                new ScreenElement('sent box: To column is the first recipient', L::Two, S::Ported, '$message->getSendTo() (getSendList()[0]->getMember())', 'an upgraded multi-recipient send names only its first recipient, as OpenPNE 3'),
                new ScreenElement('sent box: subject links to /message/check/:id', L::One, S::Ported, "link_to(\$detail_title, '@readSendMessage?id='.\$message->getId())"),
                new ScreenElement('sent box: delete trashes the sender copy only', L::One, S::Ported, 'MessageDeleteForm object_name=SendMessageData', 'sender_deleted_at; each recipient keeps their receipt'),
                new ScreenElement('sent box: withdrawn recipient leaves the To cell empty', L::Three, S::Partial, 'op_message_link_to_member(null) returns an empty string', 'OpenPNE 4 prints "Withdrawn member" rather than an empty cell'),
                // Draft box (@draftList).
                new ScreenElement('draft box: unsent authored messages, newest first', L::Two, S::Ported, 'SendMessageDataTable::getDraftMessagePager (is_send=0, is_deleted=0, created_at DESC)'),
                new ScreenElement("draft box: To column from the draft's send list", L::Two, S::Ported, '$message->getSendTo()', 'a draft has no receipt in OpenPNE 4, so the recipient is the draft_recipient_id column'),
                new ScreenElement('draft box: subject opens the edit form', L::One, S::Ported, "\$detail_url = 'message/edit?id='.\$message->getId()", 'a draft has no show page: the trash/receive/send show routes resolve no draft'),
                new ScreenElement('draft box: unaddressed draft row is unlinked', L::Two, S::Partial, "if (\$messageType == 'draft' && !\$sender->getId()): echo \$detail_title", 'OpenPNE 4 links every draft row; its edit form then opens with no recipient row and refuses the send'),
                new ScreenElement('draft box: send from the edit form', L::One, S::Ported, "executeEdit setTemplate('sendToFriend') + SendMessageForm Send", 'message.draft.update action=send materializes the receipt and clears draft_recipient_id'),
                new ScreenElement('draft box: save-as-draft submit', L::Three, S::Ported, '_sendDraftButton.php input name="is_draft"', 'no pc template includes this partial (only mobile sendToFriendInput renders it); OpenPNE 4 puts it on the draft edit form as action=draft'),
                // Trash box (@dustList).
                new ScreenElement('trash box: both sides in one list', L::One, S::Ported, 'DeletedMessageTable::getDeletedMessagePager (message_id and message_send_list_id rows)', 'UNION of senderTrashed messages and recipientTrashed receipts'),
                new ScreenElement('trash box: row icon names the box the row came from', L::Two, S::Ported, 'PluginDeletedMessage::getIcon / getIconAlt', 'Sent / Drafts / Inbox, not a read state'),
                new ScreenElement('trash box: From/To column is the other party of either side', L::Two, S::Ported, '$message->getSendFromOrTo()'),
                new ScreenElement('trash box: date is the moved-to-trash time', L::Two, S::Ported, 'DeletedMessage::created_at', 'sender_deleted_at / recipient_deleted_at'),
                new ScreenElement('trash box: subject links to /message/checkDelete/:id', L::Two, S::Ported, "link_to(\$detail_title, '@readDustMessage?id='.\$message->getViewMessageId())", 'OpenPNE 3 keys the row by the DeletedMessage id and links by the underlying message id; OpenPNE 4 keys both by the message id'),
                new ScreenElement('trash box: restore submit', L::One, S::Ported, 'input type="submit" name="restore"', "action=restore; clears the viewer's own side"),
                new ScreenElement('trash box: bulk purge confirmation page', L::One, S::Ported, "executeList setTemplate('deleteListConfirm') + deleteListConfirmSuccess.php only_hidden re-submit", 'message/bulk_purge_confirm.blade.php re-submits the ids with confirm=1, keeping id="formMessageDeleteList"'),
                new ScreenElement("trash box: purge revokes the viewer's copy only", L::Two, S::Ported, "DeletedMessageTable::deleteMessage(object_name='DeletedMessage') sets is_deleted", '*_purged_at; the row and its attachment bytes stay for the other side'),
            ],
            // showSuccess.php → message/show.blade.php
            'show' => [
                new ScreenElement('box nav sidemenu', L::Two, S::Ported, "include_partial('message/sidemenu')", 'x-message.sidemenu'),
                new ScreenElement('previous / next links within box', L::Two, S::Ported, 'getPrevious/getNext($type, $myMemberId)', 'adjacent by id within the box'),
                new ScreenElement('From / To members', L::One, S::Ported, '$fromOrToMembers (getIsSender)'),
                new ScreenElement('counterparty thumbnail', L::Two, S::Ported, 'image_tag_sf_image 76x76', 'x-classic.image 76 in the rowspan photo cell, linked to the profile'),
                new ScreenElement('subject + created-at', L::One, S::Ported, '$message->getSubject() / format_datetime'),
                new ScreenElement('body line breaks + auto-link', L::Two, S::Ported, 'auto_link_text(nl2br(getDecoratedMessageBody))', 'x-user-text (BodyText); <op:*> decoration not rendered'),
                new ScreenElement('attachment images', L::Three, S::Ported, '$message->getMessageFile()', 'thumbnails link to the full image (FilePolicy-gated to the parties)'),
                new ScreenElement('reply button (received)', L::Two, S::Ported, "button_to('message/reply')", 'shown on a received, non-draft message with a present sender'),
                new ScreenElement('delete / restore buttons', L::Two, S::Ported, 'operation buttons', 'receive/send move to trash; trash restores or purges (purge confirms first)'),
                // Sent-box show (@readSendMessage, /message/check/:id).
                new ScreenElement('sent box: no reply button', L::Two, S::Ported, "if (\$messageType != 'dust' && !\$message->getIsSender())", 'Reply is the received box alone'),
                new ScreenElement('sent box: To lists every recipient', L::Two, S::Ported, '$message->getMessageSendLists() into $fromOrToMembers', 'the receipts; a draft would use draft_recipient_id'),
                new ScreenElement('sent box: delete moves the sender copy to the trash', L::One, S::Ported, "\$deleteButton = '@deleteSendMessage?id='", 'POST message.send.trash; the recipients keep their receipts'),
                new ScreenElement('sent box: previous / next walk the sent box', L::Two, S::Ported, 'SendMessageDataTable::getPrevious/getNextSendMessageData (id <, id >)'),
                // Trash-box show (@readDustMessage, /message/checkDelete/:id).
                new ScreenElement('trash box: To or From by the side the viewer is on', L::Two, S::Ported, '$message->getIsSender()', 'a trash row can be either side'),
                new ScreenElement('trash box: restore + delete buttons, no reply', L::One, S::Ported, "showSuccess.php dust branch: restore form + \$deleteButton = '@deleteConfirmDustMessage?id='"),
                new ScreenElement('trash box: delete is a POST to the confirm page', L::Two, S::Partial, "\$form->renderFormTag(url_for('@deleteConfirmDustMessage?id='))", 'OpenPNE 4 uses a GET link labelled "Delete permanently" instead of the posted operation form'),
                new ScreenElement('trash box: previous / next', L::Three, S::Partial, 'DeletedMessageTable::getPrevious/getNextSendMessageData (message_id only)', 'OpenPNE 3 walks only the sender-trashed rows; OpenPNE 4 walks the whole box, receipts included'),
            ],
            // sendToFriendInput.php (PluginSendMessageDataForm) → message/compose.blade.php + edit.blade.php
            'sendToFriend' => [
                new ScreenElement('recipient (To) + photo', L::Two, S::Ported, '$sendMember name/photo'),
                new ScreenElement('subject input', L::One, S::Ported, 'sfWidgetFormInput subject (required)'),
                new ScreenElement('body textarea', L::One, S::Ported, 'body (required)'),
                new ScreenElement('image upload (x3)', L::Three, S::Ported, 'app_message_is_upload_images + MessageFileForm x3', 'PostImages; edit manages existing slots'),
                new ScreenElement('send + save-as-draft buttons', L::One, S::Ported, 'Send button + is_draft'),
                new ScreenElement('rich-text body editor', L::Three, S::Partial, 'opWidgetFormRichTextareaOpenPNE', 'plain textarea; OpenPNE 3 rich-text widget not ported'),
            ],
            // deleteConfirmSuccess.php (layoutC) → message/purge_confirm.blade.php. Its bulk sibling
            // deleteListConfirmSuccess.php renders from the list action, so it is inventoried there.
            'deleteConfirm' => [
                new ScreenElement('delete confirmation form', L::One, S::Ported, '$form->renderFormTag(url_for($deleteButton)) + Delete submit', "POST message.trash.purge; purge revokes the viewer's copy only"),
                new ScreenElement('heading + question paragraph', L::Two, S::Ported, "'Delete this message' / 'Do you delete this message?'"),
                new ScreenElement('formMessageDelete box', L::Two, S::Ported, '<div id="formMessageDelete" class="dparts box">'),
                new ScreenElement('layoutC single column', L::Two, S::Ported, "decorate_with('layoutC')", 'no box sidemenu, unlike the list and show screens'),
                new ScreenElement('back-to-previous line', L::Three, S::Partial, "op_include_line('backLink', link_to_function(history.back()))", 'a Cancel link to the trash box instead of the JavaScript line box'),
                new ScreenElement('reachable from the trash box only', L::Two, S::Ported, "executeDeleteConfirm: isReadable('dust'), LogicException on any other type", 'ShowDirectMessage on the trash box; 404 otherwise'),
            ],
        ];
    }
}
