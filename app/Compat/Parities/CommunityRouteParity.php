<?php

namespace App\Compat\Parities;

use App\Compat\RouteMap;
use App\Compat\RouteParity;

class CommunityRouteParity extends RouteParity
{
    protected string $module = 'community';

    protected function layouts(): array
    {
        // OpenPNE 3 community/home is layoutA (its view.yml): the community image box and member
        // grid fill the sidemenu, the pending-approval notice the top row.
        return ['community.show' => 'A'];
    }

    public function maps(): array
    {
        return [
            new RouteMap('community_home', '/community/:id', 'community.show', 'GET', op3Action: 'home'),
            new RouteMap('community_search', '/community/search', 'community.search', 'GET', op3Action: 'search'),
            // joinList is "communities this member belongs to"; ?id= shows another member's list.
            new RouteMap('community_joinlist', '/community/joinList', 'community.list_mine', 'GET', op3Action: 'joinlist'),
            new RouteMap('community_memberList', '/community/member/list', 'community.members', 'GET', op3Action: 'memberList'),

            // OpenPNE 3 serves one /community/edit for both new and edit (id presence switches),
            // and one POST for create/update. Laravel cannot route the same method+path to two
            // named routes by query param, so each is a single endpoint.
            new RouteMap('community_edit', '/community/edit', 'community.edit', 'GET', op3Action: 'edit'),
            new RouteMap('community_edit', '/community/edit', 'community.save', 'POST'),

            // join / quit / delete: OpenPNE 3 confirms on GET and runs on POST under one route;
            // split into an explicit GET confirm + POST submit (cf. FriendRouteParity unlink).
            new RouteMap('community_join', '/community/join', 'community.join.show', 'GET', op3Action: 'join'),
            new RouteMap('community_join', '/community/join', 'community.join', 'POST'),
            new RouteMap('community_quit', '/community/quit', 'community.quit.show', 'GET', op3Action: 'quit'),
            new RouteMap('community_quit', '/community/quit', 'community.quit', 'POST'),
            new RouteMap('community_delete', '/community/delete/:id', 'community.delete.show', 'GET', op3Action: 'delete'),
            new RouteMap('community_delete', '/community/delete/:id', 'community.delete', 'POST'),

            // Pending-member approval. OpenPNE 3 has no named route for it (reached through the
            // global fallback under the management page), so these are native maps; the screen
            // borrows page_community_memberManage, the body id of that admin page.
            new RouteMap(null, null, 'community.members.pending', 'GET', op3Action: 'memberManage'),
            new RouteMap(null, null, 'community.members.approve', 'POST'),
            new RouteMap(null, null, 'community.members.decline', 'POST'),

            // The member-management page: the /community/member/manage/:id URL is preserved (path
            // param) and it borrows page_community_memberManage. The per-member operations are
            // OpenPNE 4-native (OpenPNE 3 reached them through the global fallback), so they carry no
            // named OpenPNE 3 route; the GET confirms borrow the OpenPNE 3 input-page body ids.
            new RouteMap('community_memberManage', '/community/member/manage/:id', 'community.members.manage', 'GET', op3Action: 'memberManage', note: 'The member-management page is fully ported: sub-admin appoint/demote, member removal, and admin-transfer request.'),
            new RouteMap(null, null, 'community.members.appoint.show', 'GET', op3Action: 'subAdminRequest', note: 'OpenPNE 3 nominated a sub-admin through a confirmation handshake; OpenPNE 4 appoints immediately (the upgrade drops pending nominations; the appointee gets a feed notification).'),
            new RouteMap(null, null, 'community.members.appoint', 'POST'),
            new RouteMap(null, null, 'community.members.demote.show', 'GET', op3Action: 'removeSubAdmin'),
            new RouteMap(null, null, 'community.members.demote', 'POST'),
            new RouteMap(null, null, 'community.members.drop.show', 'GET', op3Action: 'dropMember'),
            new RouteMap(null, null, 'community.members.drop', 'POST'),
            new RouteMap(null, null, 'community.members.transfer.show', 'GET', op3Action: 'changeAdminRequest', note: 'The nominee accepts or declines from a banner on the community home plus a feed notification — OpenPNE 4 has no confirmation center (OpenPNE 3 routed the decision through it).'),
            new RouteMap(null, null, 'community.members.transfer', 'POST'),
            new RouteMap(null, null, 'community.members.transfer.accept', 'POST'),
            new RouteMap(null, null, 'community.members.transfer.reject', 'POST'),
        ];
    }

    public function gaps(): array
    {
        return [
            'community_deleteImage' => 'Top-image upload and removal are ported into the edit form (a file field plus a remove checkbox), so the standalone /community/deleteImage/:id URL is not preserved.',
        ];
    }

    public function acknowledgesGlobalFallback(): bool
    {
        // No community_nodefaults route in OpenPNE 3, so /:module/:action stays reachable
        // (mobile smt* actions, deleteImage, memberManage all reach it that way).
        return true;
    }
}
