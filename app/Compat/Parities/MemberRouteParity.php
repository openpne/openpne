<?php

namespace App\Compat\Parities;

use App\Compat\RouteMap;
use App\Compat\RouteParity;

/**
 * The OpenPNE 3 `member` module is large and ported incrementally; only the avatar editor
 * and the profile page are so far. So this still binds to no inventory module (like Block)
 * and records just those slices rather than enumerating the whole module — the full
 * member-module parity (inventory + gaps) switches on once enough of the module lands
 * (edit/search). op3Action carries the OpenPNE 3 page hook so the Classic body id stays
 * faithful (page_member_{action}); the legacy avatar URL is preserved by a redirect in
 * routes/web.php.
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
            new RouteMap(null, null, 'member.avatar.edit', 'GET', op3Action: 'configImage'),
            new RouteMap(null, null, 'member.avatar.update', 'POST'),
            // OpenPNE 3 member/profile (/member/{id}); page_member_profile body id.
            new RouteMap(null, null, 'member.profile.show', 'GET', op3Action: 'profile'),
        ];
    }

    public function compatRedirects(): array
    {
        return [
            '/member/image/config' => 'member.avatar.edit',
            // OpenPNE 3 profile-page aliases redirect to the canonical /member/{id}.
            '/member/profile' => 'member.profile.show',
            '/member/profile/id/:id' => 'member.profile.show',
        ];
    }
}
