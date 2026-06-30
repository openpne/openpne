<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\SourceRef;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `message_file` → OpenPNE 4 `message_files` (a personal message's image attachments).
 *
 * Only attachments of a migrated personal message are kept (the same type filter MessageUpgrade and
 * FileUpgrade's message arm use); non-personal message types are not migrated. OpenPNE 3 has no slot
 * column, so `number` is synthesized 1..N by id within the message (the order the attachments were
 * added). file.id is preserved by FileUpgrade, so file_id copies verbatim; OpenPNE 4's join row has
 * no timestamps, so the source ones are dropped.
 */
class MessageFileUpgrade extends UpgradeStep
{
    protected string $source = 'message_file';

    protected string $target = 'message_files';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'message_id' => Column::source('message_id'),
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
            'created_at' => 'OpenPNE 4 message_files is a pure join row with no timestamps (the File carries them).',
            'updated_at' => 'OpenPNE 4 message_files is a pure join row with no timestamps (the File carries them).',
        ];
    }

    /** 1..N slot per message, by id (OpenPNE 3 has no slot column; this is the order they were added). */
    private function numberExpr(): string
    {
        return '(SELECT COUNT(*) FROM '.SourceRef::table('message_file').' `m2` '
            .'WHERE `m2`.`message_id` = `message_file`.`message_id` AND `m2`.`id` <= `message_file`.`id`)';
    }
}
