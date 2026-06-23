<?php

namespace App\Compat\Parities;

use App\Compat\RouteMap;
use App\Compat\RouteParity;

class CommunityRouteParity extends RouteParity
{
    protected string $module = 'community';

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
        ];
    }

    public function gaps(): array
    {
        return [
            'community_memberManage' => 'The full member-management page (sub-admin nomination, admin transfer, member removal). Phase A ports only the pending-approval slice as a native screen (community.members.pending, reusing page_community_memberManage); the /community/member/manage/:id URL itself is not preserved.',
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
