<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\SourceRef;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `message_send_list` (opMessagePlugin) → OpenPNE 4 `direct_message_recipients`. A receipt
 * means delivered, so only rows whose parent is a sent, migrated personal message are copied: a
 * draft's recipient is folded onto direct_messages.draft_recipient_id by DirectMessageUpgrade
 * instead, and OpenPNE 3's is_read flag becomes read_at from the row's updated_at.
 */
class DirectMessageRecipientUpgrade extends UpgradeStep
{
    protected string $source = 'message_send_list';

    protected string $target = 'direct_message_recipients';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'direct_message_id' => Column::source('message_id'),
            'recipient_id' => Column::source('member_id'),
            'read_at' => Column::expr('CASE WHEN `is_read` = 1 THEN `message_send_list`.`updated_at` ELSE NULL END', uses: ['is_read', 'updated_at']),
            'recipient_deleted_at' => Column::expr($this->deletedAtExpr(), uses: ['is_deleted', 'member_id', 'id', 'updated_at']),
            'recipient_purged_at' => Column::expr($this->purgedAtExpr(), uses: ['is_deleted', 'member_id', 'id']),
            'created_at' => Column::source('created_at'),
            'updated_at' => Column::source('updated_at'),
        ];
    }

    public function filter(): ?string
    {
        return 'EXISTS (SELECT 1 FROM '.SourceRef::table('message').' `p` '
            .'WHERE `p`.`id` = `message_send_list`.`message_id` AND `p`.`is_send` = 1 AND '
            .'`p`.`message_type_id` IN (SELECT `id` FROM '.SourceRef::table('message_type')." WHERE `type_name` = 'message'))";
    }

    public function filterColumns(): array
    {
        return ['message_id'];
    }

    /**
     * Recipient-side trash timestamp: when is_deleted=1, the deleted_message pointer's created_at,
     * falling back to the row's updated_at if no pointer exists; else NULL.
     */
    private function deletedAtExpr(): string
    {
        return 'CASE WHEN `is_deleted` = 1 THEN COALESCE('
            .$this->pointerValue('`dm`.`created_at`')
            .', `message_send_list`.`updated_at`) ELSE NULL END';
    }

    /** Recipient-side purge timestamp: the deleted_message pointer's updated_at once it is purged. */
    private function purgedAtExpr(): string
    {
        return 'CASE WHEN `is_deleted` = 1 THEN '
            .$this->pointerValue('CASE WHEN `dm`.`is_deleted` = 1 THEN `dm`.`updated_at` ELSE NULL END')
            .' ELSE NULL END';
    }

    /** A value read from this recipient's deleted_message pointer (keyed by member_id + message_send_list_id). */
    private function pointerValue(string $select): string
    {
        return '(SELECT '.$select.' FROM '.SourceRef::table('deleted_message').' `dm` '
            .'WHERE `dm`.`member_id` = `message_send_list`.`member_id` AND `dm`.`message_send_list_id` = `message_send_list`.`id` '
            .'ORDER BY `dm`.`id` DESC LIMIT 1)';
    }
}
