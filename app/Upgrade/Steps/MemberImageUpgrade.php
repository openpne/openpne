<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `member_image` → OpenPNE 4 `member_images` (the avatar).
 *
 * OpenPNE 3 kept up to three images per member with an is_primary flag and showed one as the avatar
 * via Member::getImage() — `ORDER BY is_primary DESC` then fetchOne, so is_primary=1 wins, else a
 * demoted is_primary=0 over a never-primary NULL, with no tiebreak among equals. OpenPNE 4 is a single
 * avatar (member_images.member_id is unique), so the filter keeps exactly that row — replicating
 * OpenPNE 3's choice, with id ASC added as a deterministic tiebreak where OpenPNE 3 had none — and
 * drops the rest. file.id is preserved by FileUpgrade, so file_id copies verbatim.
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
        // The row Member::getImage() would show as the avatar: is_primary DESC (1, then demoted 0,
        // then never-primary NULL), id ASC as a deterministic tiebreak. The rest drop.
        return '`member_image`.`id` = (SELECT `m2`.`id` FROM `member_image` `m2` '
            .'WHERE `m2`.`member_id` = `member_image`.`member_id` '
            .'ORDER BY `m2`.`is_primary` DESC, `m2`.`id` ASC LIMIT 1)';
    }

    public function filterColumns(): array
    {
        return ['is_primary', 'member_id', 'id'];
    }
}
