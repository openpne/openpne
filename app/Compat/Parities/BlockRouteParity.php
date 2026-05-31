<?php

namespace App\Compat\Parities;

use App\Compat\RouteMap;
use App\Compat\RouteParity;

/**
 * Block has no OpenPNE 3 module: access block lived as a member-config category
 * (`/member/config?category=accessBlock`), now split into an OpenPNE 4-native Block feature.
 * So every map is OpenPNE 4-native (op3Route null) and the parity binds to no inventory module;
 * the legacy `/member/config` URL is preserved by a redirect declared in routes/web.php.
 */
class BlockRouteParity extends RouteParity
{
    protected string $module = 'block';

    public function openpne3Module(): ?string
    {
        return null;
    }

    public function maps(): array
    {
        return [
            new RouteMap(null, null, 'block.list', 'GET', op3Action: 'list'),
            new RouteMap(null, null, 'block.add.show', 'GET', op3Action: 'add'),
            new RouteMap(null, null, 'block.add', 'POST'),
            new RouteMap(null, null, 'block.remove.show', 'GET', op3Action: 'remove'),
            new RouteMap(null, null, 'block.remove.submit', 'POST'),
        ];
    }

    public function compatRedirects(): array
    {
        // Access block's OpenPNE 3 URL; redirected (not served) to the new canonical Block list.
        return ['/member/config?category=accessBlock' => 'block.list'];
    }
}
