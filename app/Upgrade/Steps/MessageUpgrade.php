<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `message` (SendMessageData, opMessagePlugin) → OpenPNE 4 `messages` (the sender's record).
 *
 * Only the personal-message type is migrated (filter: message_type.type_name = 'message'); the
 * friend/community message-types were OpenPNE 3's notification mechanism, carried by the OpenPNE 4
 * notification system instead. id is preserved so message_recipients.message_id and the self
 * references resolve by id.
 *
 * Two OpenPNE 3 quirks are folded in with correlated subqueries (the community_config treatment):
 *
 *  - draft_recipient_id: OpenPNE 4 holds a draft's pending recipient on the message and creates the
 *    receipt only on send (a receipt means "delivered"). OpenPNE 3 instead always wrote a
 *    message_send_list row, draft or sent, so a draft's recipient is read back from there and folded
 *    onto the column; sent rows get NULL. The personal-message compose form is 1:1, so a draft has a
 *    single send-list row; if anomalous data carries several, the lowest-id one wins and the rest are
 *    dropped (a draft never had a receipt to migrate).
 *  - sender_deleted_at / sender_purged_at: OpenPNE 3's trash is message.is_deleted (in/out of trash)
 *    plus a deleted_message pointer row whose own is_deleted marks a permanent purge. is_deleted=1
 *    with the pointer not purged = trash (deleted_at, from the pointer's created_at); the pointer
 *    purged = purged_at too. Rows are never hard-deleted, matching OpenPNE 4's purge.
 *
 * parent_id/thread_id (OpenPNE 3 return_message_id/thread_message_id) are null-normalized: a 0
 * (OpenPNE 3 default) or a reference outside the migrated personal-message set becomes NULL rather
 * than a dangling self reference. message_file is deferred to the file step (deferredSourceTables).
 *
 * The subqueries name message_send_list / deleted_message / message_type unqualified, so (like the
 * member_config subqueries) they are not rewritten for a source prefix or separate source database —
 * acceptable for the fleet (empty prefix, same database).
 */
class MessageUpgrade extends UpgradeStep
{
    protected string $source = 'message';

    protected string $target = 'messages';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'sender_id' => Column::source('member_id'),
            'draft_recipient_id' => Column::expr($this->draftRecipientExpr(), uses: ['is_send', 'id']),
            'subject' => Column::source('subject'),
            'body' => Column::source('body'),
            'parent_id' => Column::expr($this->portedRefExpr('return_message_id'), uses: ['return_message_id']),
            'thread_id' => Column::expr($this->portedRefExpr('thread_message_id'), uses: ['thread_message_id']),
            // is_send inverted: an undelivered (draft) row becomes is_draft=1.
            'is_draft' => Column::expr('CASE WHEN `is_send` = 1 THEN 0 ELSE 1 END', uses: ['is_send']),
            'sender_deleted_at' => Column::expr($this->deletedAtExpr(), uses: ['is_deleted', 'member_id', 'id', 'updated_at']),
            'sender_purged_at' => Column::expr($this->purgedAtExpr(), uses: ['is_deleted', 'member_id', 'id']),
            'created_at' => Column::source('created_at'),
            'updated_at' => Column::source('updated_at'),
        ];
    }

    public function filter(): ?string
    {
        return $this->isPersonalMessage('message');
    }

    public function filterColumns(): array
    {
        return ['message_type_id'];
    }

    public function gaps(): array
    {
        return [
            'foreign_id' => 'OpenPNE 3 foreign-table identifier for non-`message` message-types (friend/community notifications); those types are not migrated, so on a personal message foreign_id is always 0.',
        ];
    }

    /** SQL boolean: the `<table>` row is a personal message (message_type.type_name = 'message'). */
    private function isPersonalMessage(string $table): string
    {
        return "`{$table}`.`message_type_id` IN (SELECT `id` FROM `message_type` WHERE `type_name` = 'message')";
    }

    /** A draft's recipient, read from its (single) OpenPNE 3 send-list row; NULL for a sent message. */
    private function draftRecipientExpr(): string
    {
        return 'CASE WHEN `is_send` = 0 THEN '
            .'(SELECT `msl`.`member_id` FROM `message_send_list` `msl` '
            .'WHERE `msl`.`message_id` = `message`.`id` ORDER BY `msl`.`id` ASC LIMIT 1) '
            .'ELSE NULL END';
    }

    /** Keep a self reference only when it is non-zero and points at a migrated personal message. */
    private function portedRefExpr(string $column): string
    {
        return "CASE WHEN `{$column}` <> 0 AND EXISTS ("
            .'SELECT 1 FROM `message` `p` '
            ."WHERE `p`.`id` = `message`.`{$column}` AND "
            .$this->isPersonalMessage('p')
            .") THEN `{$column}` ELSE NULL END";
    }

    /**
     * Sender-side trash timestamp: when is_deleted=1, the deleted_message pointer's created_at (when it
     * was moved to trash), falling back to the message's updated_at if no pointer exists; else NULL.
     */
    private function deletedAtExpr(): string
    {
        return 'CASE WHEN `is_deleted` = 1 THEN COALESCE('
            .$this->pointerValue('`dm`.`created_at`')
            .', `message`.`updated_at`) ELSE NULL END';
    }

    /** Sender-side purge timestamp: the deleted_message pointer's updated_at once that pointer is purged. */
    private function purgedAtExpr(): string
    {
        return 'CASE WHEN `is_deleted` = 1 THEN '
            .$this->pointerValue('CASE WHEN `dm`.`is_deleted` = 1 THEN `dm`.`updated_at` ELSE NULL END')
            .' ELSE NULL END';
    }

    /** A value read from this sender's deleted_message pointer (keyed by member_id + message_id). */
    private function pointerValue(string $select): string
    {
        return "(SELECT {$select} FROM `deleted_message` `dm` "
            .'WHERE `dm`.`member_id` = `message`.`member_id` AND `dm`.`message_id` = `message`.`id` '
            .'ORDER BY `dm`.`id` DESC LIMIT 1)';
    }
}
