<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\SourceRef;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `message_file` → OpenPNE 4 `direct_message_files`, attachments of migrated personal
 * messages only (the DirectMessageUpgrade type filter). OpenPNE 3 has no slot column, so `number` is
 * synthesized 1..N by id within the message.
 */
class DirectMessageFileUpgrade extends UpgradeStep
{
    protected string $source = 'message_file';

    protected string $target = 'direct_message_files';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'direct_message_id' => Column::source('message_id'),
            'file_id' => Column::source('file_id'),
            'number' => Column::expr($this->numberExpr(), uses: ['message_id', 'id']),
        ];
    }

    public function filter(): ?string
    {
        return 'EXISTS (SELECT 1 FROM '.SourceRef::table('message').' `p` '
            .'WHERE `p`.`id` = `message_file`.`message_id` '
            .'AND `p`.`message_type_id` IN (SELECT `id` FROM '.SourceRef::table('message_type')." WHERE `type_name` = 'message'))";
    }

    public function filterColumns(): array
    {
        return ['message_id'];
    }

    public function gaps(): array
    {
        return [
            'created_at' => 'OpenPNE 4 direct_message_files is a pure join row with no timestamps (the File carries them).',
            'updated_at' => 'OpenPNE 4 direct_message_files is a pure join row with no timestamps (the File carries them).',
        ];
    }

    /** 1..N slot per message, by id (OpenPNE 3 has no slot column; this is the order they were added). */
    private function numberExpr(): string
    {
        return '(SELECT COUNT(*) FROM '.SourceRef::table('message_file').' `m2` '
            .'WHERE `m2`.`message_id` = `message_file`.`message_id` AND `m2`.`id` <= `message_file`.`id`)';
    }
}
