<?php

namespace App\Compat\Parities;

use App\Compat\RouteMap;
use App\Compat\RouteParity;

class CommunityTopicRouteParity extends RouteParity
{
    protected string $module = 'communityTopic';

    public function maps(): array
    {
        return [
            new RouteMap('communityTopic_list_community', '/communityTopic/listCommunity/:id', 'communityTopic.index', 'GET', op3Action: 'listCommunity'),
            new RouteMap('communityTopic_new', '/communityTopic/new/:id', 'communityTopic.new', 'GET', op3Action: 'new'),
            new RouteMap('communityTopic_create', '/communityTopic/create/:id', 'communityTopic.store', 'POST'),
            new RouteMap('communityTopic_show', '/communityTopic/:id', 'communityTopic.show', 'GET', op3Action: 'show'),
            new RouteMap('communityTopic_edit', '/communityTopic/edit/:id', 'communityTopic.edit', 'GET', op3Action: 'edit'),
            new RouteMap('communityTopic_update', '/communityTopic/update/:id', 'communityTopic.update', 'POST'),
            new RouteMap('communityTopic_delete_confirm', '/communityTopic/deleteConfirm/:id', 'communityTopic.delete.show', 'GET', op3Action: 'deleteConfirm'),
            new RouteMap('communityTopic_delete', '/communityTopic/delete/:id', 'communityTopic.delete', 'POST'),

            // communityTopicComment module: rendered under page_communityTopicComment_* (op3Module
            // override). create keys off the topic id; deleteConfirm/delete key off the comment id.
            new RouteMap('communityTopic_comment_create', '/communityTopic/:id/comment/create', 'communityTopic.comment.store', 'POST'),
            new RouteMap('communityTopic_comment_delete_confirm', '/communityTopic/comment/deleteConfirm/:id', 'communityTopic.comment.delete.show', 'GET',
                op3Action: 'deleteConfirm', op3Module: 'communityTopicComment'),
            new RouteMap('communityTopic_comment_delete', '/communityTopic/comment/delete/:id', 'communityTopic.comment.delete', 'POST'),
        ];
    }

    public function gaps(): array
    {
        return [
            // Cross-community "recently updated topics" feed (ordered by updated_at, not the
            // topic_updated_at widget). A sidebar feature for a later slice.
            'communityTopic_recently_topic_list' => 'Cross-community recently-updated topics feed; a sidebar feature, not ported.',
            // Topic keyword search. OpenPNE 3 routes it through one search surface shared with the
            // event plugin, a separate surface neither board adapter provides.
            'communityTopic_search' => 'Per-community topic search; routes to the shared topic/event search form, a separate surface the board adapter does not provide.',
            'communityTopic_search_all' => 'Global topic search; routes to the shared topic/event search form, a separate surface the board adapter does not provide.',
            'communityTopic_search_form' => 'The shared topic/event search form page; a separate search surface the board adapter does not provide.',
            'config_community_topic_notification_mail' => 'Per-community topic notification-mail opt-in; lands with the notification feature.',
        ];
    }

    public function acknowledgesGlobalFallback(): bool
    {
        // communityTopic_nodefaults (/communityTopic/* → default/error) disables the global
        // /:module/:action fallback, so the named routes are the complete reachable set.
        return false;
    }
}
