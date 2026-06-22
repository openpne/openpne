<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `member_image` → OpenPNE 4 `member_images` (the avatar).
 *
 * OpenPNE 3 kept up to three images per member with an is_primary flag; OpenPNE 4 is a single avatar
 * (member_images.member_id is unique). The filter keeps one row per member — the primary, else the
 * lowest id — and drops the rest. file.id is preserved by FileUpgrade, so file_id copies verbatim.
 *
 * The filter subquery names `member_image` unqualified (like the other correlated subqueries), so it
 * is not rewritten for a source prefix or separate source database — acceptable for the fleet.
 */
class MemberImageUpgrade extends UpgradeStep
{
    protected string $source = 'member_image';

    protected string $target = 'member_images';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'member_id' => Column::source('member_id'),
            'file_id' => Column::source('file_id'),
            'created_at' => Column::source('created_at'),
            'updated_at' => Column::source('updated_at'),
        ];
    }

    public function filter(): ?string
    {
        return '`member_image`.`id` = (SELECT `m2`.`id` FROM `member_image` `m2` '
            .'WHERE `m2`.`member_id` = `member_image`.`member_id` '
            .'ORDER BY `m2`.`is_primary` DESC, `m2`.`id` ASC LIMIT 1)';
    }

    public function filterColumns(): array
    {
        return ['is_primary', 'member_id', 'id'];
    }
}
