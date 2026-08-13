<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `community_category` → OpenPNE 4 `group_categories`.
 *
 * OpenPNE 3 stored categories as a NestedSet tree (lft/rgt/level/tree_key) with a synthetic
 * root at lft=1 that is never a selectable category — the pc_frontend only offers the lft>1
 * children. OpenPNE 4 keeps a flat master, so the root is not migrated (filter lft>1) and the
 * tree columns are dropped; `parent_id` is a new column left to its null default, not derived
 * from the tree.
 */
class GroupCategoryUpgrade extends UpgradeStep
{
    protected string $source = 'community_category';

    protected string $target = 'group_categories';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'name' => Column::source('name'),
            'is_allow_member_group' => Column::source('is_allow_member_community'),
            'sort_order' => Column::source('sort_order'),
            'created_at' => Column::source('created_at'),
            'updated_at' => Column::source('updated_at'),
        ];
    }

    public function filter(): ?string
    {
        // Drop the synthetic root; only lft>1 children are real, selectable categories.
        return 'lft > 1';
    }

    public function filterColumns(): array
    {
        return ['lft'];
    }

    public function targetDefaults(): array
    {
        // New flat-hierarchy column with no OpenPNE 3 NestedSet equivalent; rely on the null default.
        return ['parent_id'];
    }

    public function gaps(): array
    {
        return [
            'tree_key' => 'NestedSet tree key; OpenPNE 4 categories are flat.',
            'rgt' => 'NestedSet right bound; the tree is dropped.',
            'level' => 'NestedSet depth; the tree is dropped.',
        ];
    }
}
