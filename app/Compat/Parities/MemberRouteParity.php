<?php

namespace App\Compat\Parities;

use App\Compat\RouteMap;
use App\Compat\RouteParity;

/**
 * The OpenPNE 3 `member` module is the member hub — profile, avatar, search, auth, config,
 * invite, withdrawal — ported feature by feature. This parity owns the whole module: the
 * profile / avatar / search / edit slices are mapped, and the actions still living in
 * OpenPNE 3 (or moved to another feature, like Fortify auth) are gapped, so an un-ported
 * member URL surfaces instead of being silently dropped. Actions move from gap to map as
 * their feature lands. The module keeps OpenPNE 3's global /:module/:action fallback on, so
 * named-route coverage is non-exhaustive — acknowledged below.
 *
 * op3Action carries the OpenPNE 3 page hook so the Classic body id stays faithful
 * (page_member_{action}); the moved profile / avatar URLs are preserved by the *_compat
 * redirects declared in routes/web.php, mapped here so they stay accounted in the inventory.
 */
class MemberRouteParity extends RouteParity
{
    protected string $module = 'member';

    public function maps(): array
    {
        return [
            // Profile page — OpenPNE 4 /member/{id} preserves the OpenPNE 3 URL in place.
            // OpenPNE 3 kept a second /member/:id route "for BC" (member_profile); same page.
            new RouteMap('obj_member_profile', '/member/:id', 'member.profile.show', 'GET', op3Action: 'profile'),
            new RouteMap('member_profile', '/member/:id', 'member.profile.show', 'GET', op3Action: 'profile'),
            // Own-profile aliases preserved by redirect to the canonical /member/{id}.
            new RouteMap('member_profile_mine', '/member/profile', 'member.profile.mine_compat', 'GET', op3Action: 'profile'),
            new RouteMap('member_profile_raw', '/member/profile/id/:id/*', 'member.profile.raw_compat', 'GET', op3Action: 'profile'),
            // Avatar editor — the canonical moved to /member/avatar; the OpenPNE 3 URL is
            // preserved by member.image.config_compat, and the upload POSTs to member.avatar.update.
            new RouteMap('member_config_image', '/member/image/config', 'member.image.config_compat', 'GET', op3Action: 'configImage'),
            new RouteMap(null, null, 'member.avatar.edit', 'GET', op3Action: 'configImage'),
            new RouteMap(null, null, 'member.avatar.update', 'POST'),
            // Member search.
            new RouteMap('member_search', '/member/search', 'member.search', 'GET', op3Action: 'search'),
            // Profile editor — one OpenPNE 3 route (ANY) splits into a GET form + POST submit.
            new RouteMap('member_editProfile', '/member/edit/profile', 'member.profile.edit', 'GET', op3Action: 'editProfile'),
            new RouteMap('member_editProfile', '/member/edit/profile', 'member.profile.update', 'POST'),
        ];
    }

    public function gaps(): array
    {
        return [
            'homepage' => 'OpenPNE 3 member/home is the logged-in portal at /; OpenPNE 4 serves a separate dashboard, so the portal home is not ported.',
            'member_index' => 'OpenPNE 3 member/home alias at /member; the same un-ported portal home as homepage.',
            'login' => 'Login is served by Fortify at /login, not the OpenPNE 3 /member/login URL.',
            'member_logout' => 'Logout is served by Fortify at POST /logout (OpenPNE 3 also allowed GET).',
            'member_delete' => 'Member withdrawal (/leave) is not ported.',
            'member_invite' => 'Member invitation (/invite) is not ported.',
            'member_config' => 'The member config screen is not ported; its access-block category is redirected by the Block parity.',
            'member_config_jsonapi' => 'The legacy config JSON API (/member/config/jsonapi) is not ported.',
            'global_changeLanguage' => 'Locale switching is POST /locale (locale.switch), not the OpenPNE 3 GET /language URL.',
        ];
    }

    public function acknowledgesGlobalFallback(): bool
    {
        return true;
    }
}
