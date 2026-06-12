<?php

namespace App\Compat\Parities;

use App\Compat\CompatLevel as L;
use App\Compat\RouteMap;
use App\Compat\RouteParity;
use App\Compat\ScreenElement;
use App\Compat\ScreenStatus as S;

/**
 * The OpenPNE 3 `member` module is the member hub — profile, avatar, search, auth, config,
 * invite, withdrawal — ported feature by feature. This parity owns the whole module: the
 * home / profile / avatar / search / edit slices are mapped, and the actions still living in
 * OpenPNE 3 (or moved to another feature, like Fortify auth) are gapped, so an un-ported
 * member URL surfaces instead of being silently dropped. Actions move from gap to map as
 * their feature lands. The module keeps OpenPNE 3's global /:module/:action fallback on, so
 * named-route coverage is non-exhaustive — acknowledged below.
 *
 * op3Action carries the OpenPNE 3 page hook so the Classic body id stays faithful
 * (page_member_{action}). A moved URL is kept reachable by a routes/web.php redirect: mapped
 * to that redirect route when its target needs the request (own-profile / raw alias), or
 * recorded as a compatRedirect when the move is static (the avatar editor).
 */
class MemberRouteParity extends RouteParity
{
    protected string $module = 'member';

    public function maps(): array
    {
        return [
            // Portal home — OpenPNE 3 member/home at the canonical root (/), with /member as its
            // alias (member_index). Both resolve by surface; the Classic home keeps page_member_home.
            new RouteMap('homepage', '/', 'home', 'GET', op3Action: 'home'),
            new RouteMap('member_index', '/member', 'member.index_compat', 'GET', op3Action: 'home'),
            // Profile page — OpenPNE 4 /member/{id} preserves the OpenPNE 3 URL in place.
            // OpenPNE 3 kept a second /member/:id route "for BC" (member_profile); same page.
            new RouteMap('obj_member_profile', '/member/:id', 'member.profile.show', 'GET', op3Action: 'profile'),
            new RouteMap('member_profile', '/member/:id', 'member.profile.show', 'GET', op3Action: 'profile'),
            // Own-profile aliases preserved by redirect to the canonical /member/{id}.
            new RouteMap('member_profile_mine', '/member/profile', 'member.profile.mine_compat', 'GET', op3Action: 'profile'),
            new RouteMap('member_profile_raw', '/member/profile/id/:id/*', 'member.profile.raw_compat', 'GET', op3Action: 'profile'),
            // Avatar editor — OpenPNE 3's member_config_image (one ANY route) served the form on
            // GET and the upload on POST; OpenPNE 4 splits them at the new /member/avatar. The
            // legacy /member/image/config URL is preserved by a static redirect (compatRedirects).
            new RouteMap('member_config_image', '/member/image/config', 'member.avatar.edit', 'GET', op3Action: 'configImage'),
            new RouteMap('member_config_image', '/member/image/config', 'member.avatar.update', 'POST'),
            // Member search.
            new RouteMap('member_search', '/member/search', 'member.search', 'GET', op3Action: 'search'),
            // Profile editor — one OpenPNE 3 route (ANY) splits into a GET form + POST submit.
            new RouteMap('member_editProfile', '/member/edit/profile', 'member.profile.edit', 'GET', op3Action: 'editProfile'),
            new RouteMap('member_editProfile', '/member/edit/profile', 'member.profile.update', 'POST'),
            // Login — Fortify owns /login; the OpenPNE 3 /member/login/* URL is preserved by a static
            // redirect (compatRedirects), and the Classic body id stays page_member_login.
            new RouteMap('login', '/member/login/*', 'login', 'GET', op3Action: 'login'),
            // Member invitation — OpenPNE 3 member/invite at /invite; the GET form and POST send share
            // the URL. Keeps page_member_invite for the Classic body id.
            new RouteMap('member_invite', '/invite', 'member.invite', 'GET', op3Action: 'invite'),
            new RouteMap('member_invite', '/invite', 'member.invite.submit', 'POST'),
        ];
    }

    public function gaps(): array
    {
        return [
            'member_logout' => 'Logout is served by Fortify at POST /logout (OpenPNE 3 also allowed GET).',
            'member_delete' => 'Member withdrawal (/leave) is not ported.',
            'member_config' => 'The member config screen is not ported; its access-block category is redirected by the Block parity.',
            'member_config_jsonapi' => 'The legacy config JSON API (/member/config/jsonapi) is not ported.',
            'global_changeLanguage' => 'Locale switching is POST /locale (locale.switch), not the OpenPNE 3 GET /language URL.',
        ];
    }

    public function compatRedirects(): array
    {
        return [
            // The avatar editor's OpenPNE 3 URL; redirected (not served) to the new canonical editor.
            '/member/image/config' => 'member.avatar.edit',
            // OpenPNE 3 login lived at /member/login/*; redirected to Fortify's canonical /login.
            '/member/login' => 'login',
        ];
    }

    /**
     * Surface elements per OpenPNE 3 member template. Only the login screen is inventoried here;
     * the form is reproduced in resources/views/auth/login.blade.php (rendered by Fortify's
     * surface-aware loginView). Levels follow docs/internals/classic-compatibility.md.
     */
    public function screens(): array
    {
        return [
            // member/login → _partsLogin.php (.loginForm) → resources/views/auth/login.blade.php
            'login' => [
                new ScreenElement('mail address + password inputs', L::One, S::Ported, 'opAuthLoginFormMailAddress (mail_address, password)', 'field names not preserved (email/password, Level 3)'),
                new ScreenElement('login button', L::Two, S::Ported, '_partsLogin input_submit'),
                new ScreenElement('password reminder link', L::One, S::Ported, 'link_to help_login_error_action', 'links to /forgot-password (password.request)'),
                new ScreenElement('self-registration link', L::Two, S::Ported, 'link_to self_invite_action', 'shown unconditionally; OpenPNE 3 gated it on invite_mode==2 + enable_registration (config gate deferred)'),
                new ScreenElement('login gadget zones (top/side/contents/bottom)', L::Three, S::Missing, 'op_login_gadget_list', 'gadget layout not ported; single-column LayoutC form only'),
            ],
        ];
    }

    public function acknowledgesGlobalFallback(): bool
    {
        return true;
    }
}
