<?php

/**
 * OpenPNE 3 pc_frontend route inventory, by module — the route-parity SSoT the Classic
 * adapters map from. See database/parity/README.md for how to regenerate.
 *
 * Each module lists its named routes (name => [URL pattern, method]) from the OpenPNE 3
 * route collection. `method` is the route's sf_method constraint: 'POST' where the route
 * declares sf_method => ['post'], otherwise 'ANY' (no constraint — these are GET-reachable).
 * The distinction drives URL-compatibility scope: only GET-reachable URLs are bookmarked /
 * mailed / linked, so only they carry a URL-preservation obligation. POST-only routes are
 * still covered for completeness, but are out of the URL-compatibility contract.
 *
 * OpenPNE 3 also has a global deprecated fallback `/:module/:action/*`
 * (opSymfonyDefaultRouteCollection), so by default any module action is reachable by URL
 * even without a named route. `disables_global_fallback` records that a module turns this
 * off — opDiaryPlugin adds diary_nodefaults (`/diary/*` → default/error), which makes the
 * named routes the complete set of reachable diary URLs.
 */

return [
    'member' => [
        // No member_nodefaults route, so the global /:module/:action fallback stays on:
        // un-named member actions remain URL-reachable. Only the profile / avatar / search /
        // edit slices are ported; the rest (home, auth, config, invite, withdrawal) are gapped.
        // Every route is sf_method-unconstrained or [get] — none is POST-only — so all are ANY.
        'disables_global_fallback' => false,
        'routes' => [
            'homepage' => ['/', 'ANY'],
            'member_index' => ['/member', 'ANY'],
            'obj_member_profile' => ['/member/:id', 'ANY'],
            'member_profile' => ['/member/:id', 'ANY'],
            'member_profile_mine' => ['/member/profile', 'ANY'],
            'member_profile_raw' => ['/member/profile/id/:id/*', 'ANY'],
            'member_config_image' => ['/member/image/config', 'ANY'],
            'member_search' => ['/member/search', 'ANY'],
            'member_editProfile' => ['/member/edit/profile', 'ANY'],
            'login' => ['/member/login/*', 'ANY'],
            'member_logout' => ['/logout', 'ANY'],
            'member_delete' => ['/leave', 'ANY'],
            'member_invite' => ['/invite', 'ANY'],
            'member_config' => ['/member/config', 'ANY'],
            'member_config_jsonapi' => ['/member/config/jsonapi', 'ANY'],
            'global_changeLanguage' => ['/language', 'ANY'],
        ],
    ],
    'friend' => [
        // No friend_nodefaults route, so the global /:module/:action fallback stays on:
        // un-named actions (e.g. /friend/link) remain reachable. obj_friend_unlink declares
        // sf_method [get, post], so it is not POST-only — ANY (GET-reachable) here.
        'disables_global_fallback' => false,
        'routes' => [
            'friend_list' => ['/friend/list', 'ANY'],
            'friend_manage' => ['/friend/manage', 'ANY'],
            'obj_friend_unlink' => ['/friend/unlink/:id', 'ANY'],
            'friend_show_image' => ['/friend/showImage/:id', 'ANY'],
        ],
    ],
    'diary' => [
        'disables_global_fallback' => true,
        'routes' => [
            'diary_index' => ['/diary', 'ANY'],
            'diary_search' => ['/diary/search', 'ANY'],
            'diary_list' => ['/diary/list', 'ANY'],
            'diary_list_mine' => ['/diary/listMember', 'ANY'],
            'diary_list_member' => ['/diary/listMember/:id', 'ANY'],
            'diary_list_member_year_month' => ['/diary/listMember/:id/:year/:month', 'ANY'],
            'diary_list_member_year_month_day' => ['/diary/listMember/:id/:year/:month/:day', 'ANY'],
            'diary_list_friend' => ['/diary/listFriend', 'ANY'],
            'diary_show' => ['/diary/:id', 'ANY'],
            'diary_new' => ['/diary/new', 'ANY'],
            'diary_create' => ['/diary/create', 'POST'],
            'diary_edit' => ['/diary/edit/:id', 'ANY'],
            'diary_update' => ['/diary/update/:id', 'POST'],
            'diary_delete_confirm' => ['/diary/deleteConfirm/:id', 'ANY'],
            'diary_delete' => ['/diary/delete/:id', 'POST'],
            'diary_comment_history' => ['/diary/comment/history', 'ANY'],
            'diary_comment_create' => ['/diary/:id/comment/create', 'POST'],
            'diary_comment_delete_confirm' => ['/diary/comment/deleteConfirm/:id', 'ANY'],
            'diary_comment_delete' => ['/diary/comment/delete/:id', 'POST'],
        ],
    ],
    'community' => [
        // No community_nodefaults route, so the global /:module/:action fallback stays on:
        // the mobile smt* actions, deleteImage, and memberManage remain URL-reachable un-named.
        // Every community route is sf_method-unconstrained, so all are ANY (GET-reachable).
        'disables_global_fallback' => false,
        'routes' => [
            'community_joinlist' => ['/community/joinList', 'ANY'],
            'community_search' => ['/community/search', 'ANY'],
            'community_edit' => ['/community/edit', 'ANY'],
            'community_delete' => ['/community/delete/:id', 'ANY'],
            'community_deleteImage' => ['/community/deleteImage', 'ANY'],
            'community_memberList' => ['/community/member/list', 'ANY'],
            'community_memberManage' => ['/community/member/manage/:id', 'ANY'],
            'community_join' => ['/community/join', 'ANY'],
            'community_quit' => ['/community/quit', 'ANY'],
            'community_home' => ['/community/:id', 'ANY'],
        ],
    ],
    'communityTopic' => [
        // opCommunityTopicPlugin adds communityTopic_nodefaults (/communityTopic/* →
        // default/error), so the global /:module/:action fallback is off: the named routes are the
        // complete reachable set. The comment routes are inventoried here (they belong to the topic
        // plugin) though they render under the communityTopicComment module.
        'disables_global_fallback' => true,
        'routes' => [
            'communityTopic_list_community' => ['/communityTopic/listCommunity/:id', 'ANY'],
            'communityTopic_show' => ['/communityTopic/:id', 'ANY'],
            'communityTopic_new' => ['/communityTopic/new/:id', 'ANY'],
            'communityTopic_create' => ['/communityTopic/create/:id', 'POST'],
            'communityTopic_edit' => ['/communityTopic/edit/:id', 'ANY'],
            'communityTopic_update' => ['/communityTopic/update/:id', 'POST'],
            'communityTopic_delete_confirm' => ['/communityTopic/deleteConfirm/:id', 'ANY'],
            'communityTopic_delete' => ['/communityTopic/delete/:id', 'POST'],
            'communityTopic_comment_create' => ['/communityTopic/:id/comment/create', 'POST'],
            'communityTopic_comment_delete_confirm' => ['/communityTopic/comment/deleteConfirm/:id', 'ANY'],
            'communityTopic_comment_delete' => ['/communityTopic/comment/delete/:id', 'POST'],
            'communityTopic_recently_topic_list' => ['/communityTopic/recentlyTopicList', 'ANY'],
            'communityTopic_search' => ['/communityTopic/search/:id', 'ANY'],
            'communityTopic_search_all' => ['/communityTopic/search', 'ANY'],
            'communityTopic_search_form' => ['/communityTopic/searchForm', 'ANY'],
            'config_community_topic_notification_mail' => ['/config/communityTopicNotificationMail/:id', 'POST'],
        ],
    ],
    'communityEvent' => [
        // opCommunityTopicPlugin's event collection adds communityEvent_nodefaults
        // (/communityEvent/* → default/error), so the global /:module/:action fallback is off: the
        // named routes are the complete reachable set. The comment routes are inventoried here (they
        // belong to the plugin) though they render under the communityEventComment module. Events
        // reuse the same route-collection class as topics under the `event` name, so the board /
        // comment shape mirrors communityTopic; the additions are the RSVP roster (memberList) and
        // the scheduling fields carried in create/update.
        'disables_global_fallback' => true,
        'routes' => [
            'communityEvent_list_community' => ['/communityEvent/listCommunity/:id', 'ANY'],
            'communityEvent_show' => ['/communityEvent/:id', 'ANY'],
            'communityEvent_new' => ['/communityEvent/new/:id', 'ANY'],
            'communityEvent_create' => ['/communityEvent/create/:id', 'POST'],
            'communityEvent_edit' => ['/communityEvent/edit/:id', 'ANY'],
            'communityEvent_update' => ['/communityEvent/update/:id', 'POST'],
            'communityEvent_delete_confirm' => ['/communityEvent/deleteConfirm/:id', 'ANY'],
            'communityEvent_delete' => ['/communityEvent/delete/:id', 'POST'],
            'communityEvent_memberList' => ['/communityEvent/:id/memberList', 'ANY'],
            'communityEvent_comment_create' => ['/communityEvent/:id/comment/create', 'POST'],
            'communityEvent_comment_delete_confirm' => ['/communityEvent/comment/deleteConfirm/:id', 'ANY'],
            'communityEvent_comment_delete' => ['/communityEvent/comment/delete/:id', 'POST'],
            'communityEvent_recently_event_list' => ['/communityEvent/recentlyEventList', 'ANY'],
            'communityEvent_search_all' => ['/communityEvent/search', 'ANY'],
        ],
    ],
    'message' => [
        // opMessagePlugin registers its UI routes programmatically (opMessagePluginRouting). It also
        // adds message_no_default (/message/* → default/error), but compose/reply/edit/restore have
        // no named route and are reached through the module/action fallback, so the named routes are
        // NOT the complete reachable set — fallback stays acknowledged (disables_global_fallback off).
        // OpenPNE 3 left the delete/deleteComplete routes method-unconstrained, but they are
        // CSRF-protected button_to submits with no working GET form, so they carry no GET
        // URL-preservation obligation and are recorded POST (the obligation the method field drives,
        // not literal sf_method); only the deleteConfirm page is a GET screen. *.json are the
        // smartphone/API endpoints; messageChain is the smartphone thread view.
        'disables_global_fallback' => false,
        'routes' => [
            'receiveList' => ['/message/receiveList', 'ANY'],
            'sendList' => ['/message/sendList', 'ANY'],
            'draftList' => ['/message/draftList', 'ANY'],
            'dustList' => ['/message/dustList', 'ANY'],
            'readReceiveMessage' => ['/message/read/:id', 'ANY'],
            'readSendMessage' => ['/message/check/:id', 'ANY'],
            'readDustMessage' => ['/message/checkDelete/:id', 'ANY'],
            'deleteReceiveMessage' => ['/message/deleteReceiveMessage/:id', 'POST'],
            'deleteSendMessage' => ['/message/deleteSendMessage/:id', 'POST'],
            'deleteDustMessage' => ['/message/deleteComplete/:id', 'POST'],
            'deleteConfirmDustMessage' => ['/message/deleteConfirm/:id', 'ANY'],
            'messageChain' => ['/message/chain/:id', 'ANY'],
            'message_post' => ['/message/post.json', 'POST'],
            'message_search' => ['/message/search.json', 'POST'],
            'recent_message_list' => ['/message/recentList.json', 'POST'],
        ],
    ],
    'timeline' => [
        // opTimelinePlugin adds no timeline_nodefaults route, so the global /:module/:action
        // fallback stays on: the API endpoints (post/search/show.json) and the smt* mobile views
        // are reached un-named. The three pc_frontend named routes are the member / community /
        // SNS timeline feeds; every one is sf_method-unconstrained, so all are ANY (GET-reachable).
        'disables_global_fallback' => false,
        'routes' => [
            'member_timeline' => ['/member/:id/timeline', 'ANY'],
            'community_timeline' => ['/community/:id/timeline', 'ANY'],
            'sns_timeline' => ['/sns/timeline', 'ANY'],
        ],
    ],
];
