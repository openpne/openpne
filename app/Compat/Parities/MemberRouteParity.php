<?php

namespace App\Compat\Parities;

use App\Compat\RouteMap;
use App\Compat\RouteParity;

/**
 * The OpenPNE 3 `member` module is large and not ported; only the avatar editor slice
 * is. So this binds to no inventory module (like Block) and records just that slice:
 * OpenPNE 3 served it at member/configImage (`/member/image/config`), now the
 * OpenPNE 4 `member.avatar.*` routes, with the legacy URL preserved by a redirect
 * declared in routes/web.php. The full member-module parity comes when that module is
 * ported.
 */
class MemberRouteParity extends RouteParity
{
    protected string $module = 'member';

    public function openpne3Module(): ?string
    {
        return null;
    }

    public function maps(): array
    {
        return [
            // op3Action carries the OpenPNE 3 page hook so the Classic editor keeps its
            // page_member_configImage body id.
            new RouteMap(null, null, 'member.avatar.edit', 'GET', op3Action: 'configImage'),
            new RouteMap(null, null, 'member.avatar.update', 'POST'),
        ];
    }

    public function compatRedirects(): array
    {
        return ['/member/image/config' => 'member.avatar.edit'];
    }
}
