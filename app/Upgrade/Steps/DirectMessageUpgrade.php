<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\SourceRef;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `message` (opMessagePlugin) → OpenPNE 4 `direct_messages`, the two mailbox types only
 * (MIGRATED_TYPES): the other types were OpenPNE 3's notification letters. A draft's recipient is
 * folded from its message_send_list row onto draft_recipient_id, and OpenPNE 3's trash
 * (message.is_deleted plus a deleted_message pointer whose own is_deleted marks the purge) onto
 * sender_deleted_at / sender_purged_at.
 */
class DirectMessageUpgrade extends UpgradeStep
{
    /**
     * A personal message and a community message are both one sender's letter to the members listed
     * in message_send_list; the other message types announce a state the member / community steps carry.
     */
    public const MIGRATED_TYPES = ['message', 'community_message'];

    protected string $source = 'message';

    protected string $target = 'direct_messages';

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
        return self::isMailboxMessage('message');
    }

    public function filterColumns(): array
    {
        return ['message_type_id'];
    }

    public function gaps(): array
    {
        return [
            'foreign_id' => 'OpenPNE 3 foreign-table identifier: 0 on a personal message, the community id on a community message (shown by OpenPNE 3 as "<community> 参加者" in place of the recipient list on both sides; OpenPNE 4 shows the sender and the recipients), and the notification types\' target, which are not migrated.',
        ];
    }

    /** SQL boolean: the `<table>` row is of a migrated type (message_type.type_name in MIGRATED_TYPES). */
    public static function isMailboxMessage(string $table): string
    {
        $types = implode(', ', array_map(static fn (string $type): string => "'{$type}'", self::MIGRATED_TYPES));

        return "`{$table}`.`message_type_id` IN (SELECT `id` FROM ".SourceRef::table('message_type')." WHERE `type_name` IN ({$types}))";
    }

    /**
     * SQL boolean: this `message_send_list` row is the one folded onto its draft's draft_recipient_id,
     * the lowest id where anomalous data carries several (the compose form is 1:1). Public because
     * ActiveMember's preflight scope must count exactly this row.
     */
    public static function draftRecipientRowSelector(): string
    {
        return '`message_send_list`.`id` = (SELECT MIN(`first`.`id`) FROM '.SourceRef::table('message_send_list').' `first`'
            .' WHERE `first`.`message_id` = `message_send_list`.`message_id`)';
    }

    /** A draft's recipient, read from its (single) OpenPNE 3 send-list row; NULL for a sent message. */
    private function draftRecipientExpr(): string
    {
        return 'CASE WHEN `is_send` = 0 THEN '
            .'(SELECT `message_send_list`.`member_id` FROM '.SourceRef::table('message_send_list').' `message_send_list` '
            .'WHERE `message_send_list`.`message_id` = `message`.`id` AND '.self::draftRecipientRowSelector().' LIMIT 1) '
            .'ELSE NULL END';
    }

    /** Keep a self reference only when it is non-zero and points at a migrated message. */
    private function portedRefExpr(string $column): string
    {
        return "CASE WHEN `{$column}` <> 0 AND EXISTS ("
            .'SELECT 1 FROM '.SourceRef::table('message').' `p` '
            ."WHERE `p`.`id` = `message`.`{$column}` AND "
            .self::isMailboxMessage('p')
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
        return '(SELECT '.$select.' FROM '.SourceRef::table('deleted_message').' `dm` '
            .'WHERE `dm`.`member_id` = `message`.`member_id` AND `dm`.`message_id` = `message`.`id` '
            .'ORDER BY `dm`.`id` DESC LIMIT 1)';
    }
}
