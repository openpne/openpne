<?php

namespace App\Compat\Parities;

use App\Compat\RouteMap;
use App\Compat\RouteParity;

class CommunityEventRouteParity extends RouteParity
{
    protected string $module = 'communityEvent';

    public function maps(): array
    {
        return [
            new RouteMap('communityEvent_list_community', '/communityEvent/listCommunity/:id', 'communityEvent.index', 'GET', op3Action: 'listCommunity'),
            new RouteMap('communityEvent_new', '/communityEvent/new/:id', 'communityEvent.new', 'GET', op3Action: 'new'),
            new RouteMap('communityEvent_create', '/communityEvent/create/:id', 'communityEvent.store', 'POST'),
            new RouteMap('communityEvent_show', '/communityEvent/:id', 'communityEvent.show', 'GET', op3Action: 'show'),
            new RouteMap('communityEvent_edit', '/communityEvent/edit/:id', 'communityEvent.edit', 'GET', op3Action: 'edit'),
            new RouteMap('communityEvent_update', '/communityEvent/update/:id', 'communityEvent.update', 'POST'),
            new RouteMap('communityEvent_delete_confirm', '/communityEvent/deleteConfirm/:id', 'communityEvent.delete.show', 'GET', op3Action: 'deleteConfirm'),
            new RouteMap('communityEvent_delete', '/communityEvent/delete/:id', 'communityEvent.delete', 'POST'),
            new RouteMap('communityEvent_memberList', '/communityEvent/:id/memberList', 'communityEvent.member_list', 'GET', op3Action: 'memberList'),

            // communityEventComment module: rendered under page_communityEventComment_* (op3Module
            // override). create keys off the event id; deleteConfirm/delete key off the comment id.
            new RouteMap('communityEvent_comment_create', '/communityEvent/:id/comment/create', 'communityEvent.comment.store', 'POST'),
            new RouteMap('communityEvent_comment_delete_confirm', '/communityEvent/comment/deleteConfirm/:id', 'communityEvent.comment.delete.show', 'GET',
                op3Action: 'deleteConfirm', op3Module: 'communityEventComment'),
            new RouteMap('communityEvent_comment_delete', '/communityEvent/comment/delete/:id', 'communityEvent.comment.delete', 'POST'),
        ];
    }

    public function gaps(): array
    {
        return [
            // Cross-community "recently updated events" feed (ordered by updated_at): a sidebar
            // widget, outside the per-community event board this adapter serves.
            'communityEvent_recently_event_list' => 'Cross-community recently-updated events feed; a sidebar widget outside the event board.',
            // Global event search. OpenPNE 3 routes it to the shared topic/event search action, a
            // separate search surface the board adapter does not provide.
            'communityEvent_search_all' => 'Global event search; routes to the shared topic/event search form, a separate surface the board adapter does not provide.',
        ];
    }

    public function acknowledgesGlobalFallback(): bool
    {
        // communityEvent_nodefaults (/communityEvent/* → default/error) disables the global
        // /:module/:action fallback, so the named routes are the complete reachable set.
        return false;
    }
}
