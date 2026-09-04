<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\SourceRef;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `member_image` → OpenPNE 4 `member_images`, one avatar per member. OpenPNE 3 kept up to
 * three images and showed the one Member::getImage() picked (`ORDER BY is_primary DESC`, no
 * tiebreak), so the filter keeps that row with id ASC as a deterministic tiebreak and drops the rest.
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
        // The row Member::getImage() would show: is_primary DESC (1, then a demoted 0, then
        // never-primary NULL), id ASC as the tiebreak OpenPNE 3 lacked.
        return '`member_image`.`id` = (SELECT `m2`.`id` FROM '.SourceRef::table('member_image').' `m2` '
            .'WHERE `m2`.`member_id` = `member_image`.`member_id` '
            .'ORDER BY `m2`.`is_primary` DESC, `m2`.`id` ASC LIMIT 1)';
    }

    public function filterColumns(): array
    {
        return ['is_primary', 'member_id', 'id'];
    }

    public function memberRefs(): array
    {
        return ['member_id'];
    }
}
