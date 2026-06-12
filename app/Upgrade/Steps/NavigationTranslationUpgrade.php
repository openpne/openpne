<?php

namespace App\Upgrade\Steps;

use App\Models\Navigation;
use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `navigation_translation` → OpenPNE 4 `navigation_translations` (localised caption per
 * (id, lang); id is the navigation id). Restricted to the PC navigation types NavigationUpgrade
 * copies, so a translation never references a navigations row that was filtered out.
 */
class NavigationTranslationUpgrade extends UpgradeStep
{
    protected string $source = 'navigation_translation';

    protected string $target = 'navigation_translations';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'caption' => Column::source('caption'),
            'lang' => Column::source('lang'),
        ];
    }

    public function filter(): ?string
    {
        // The sibling `navigation` is named unqualified (like MemberUpgrade's member_config
        // subquery): correct for the fleet (no source prefix, same database).
        return sprintf('`id` IN (SELECT `id` FROM `navigation` WHERE `type` IN (%s))', $this->typeList());
    }

    public function filterColumns(): array
    {
        return ['id'];
    }

    private function typeList(): string
    {
        return implode(', ', array_map(static fn (string $t): string => "'{$t}'", Navigation::TYPES));
    }
}
