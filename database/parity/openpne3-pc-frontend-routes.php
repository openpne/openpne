<?php

/**
 * The OpenPNE 3 pc_frontend route inventory, per module: name => [URL pattern, method]. How to
 * regenerate it, and what `method` and `disables_global_fallback` mean: database/parity/README.md.
 */

return [
    'member' => [
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
        // obj_friend_unlink declares sf_method [get, post], so it is GET-reachable and recorded ANY.
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
        // The comment routes are inventoried here, with the plugin that owns them, though they
        // render under the communityTopicComment module.
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
        // The comment routes are inventoried here, with the plugin that owns them, though they
        // render under the communityEventComment module.
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
        // OpenPNE 3 leaves delete/deleteComplete method-unconstrained, but they are CSRF-protected
        // button_to submits with no GET form, so they are recorded POST.
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
        'disables_global_fallback' => false,
        'routes' => [
            'member_timeline' => ['/member/:id/timeline', 'ANY'],
            'community_timeline' => ['/community/:id/timeline', 'ANY'],
            'sns_timeline' => ['/sns/timeline', 'ANY'],
        ],
    ],
    'default' => [
        // A plugin's own `*_nodefaults` catch-all also targets default/error but is recorded as that
        // module's `disables_global_fallback`, not here.
        'disables_global_fallback' => true,
        'routes' => [
            'error' => ['/default/error', 'ANY'],
            'global_search' => ['/search', 'ANY'],
            'global_privacy_policy' => ['/privacyPolicy', 'ANY'],
            'global_user_agreement' => ['/userAgreement', 'ANY'],
            'url_for' => ['/default/urlFor.txt', 'ANY'],
            'customizing_css' => ['/cache/css/customizing.:sf_format', 'ANY'],
            'member_profile_no_default' => ['/member/profile/*', 'ANY'],
            'privacy_policy' => ['/default/privacyPolicy', 'ANY'],
            'user_agreement' => ['/default/userAgreement', 'ANY'],
            'no_default' => ['/default/*', 'ANY'],
            'no_symfony' => ['/symfony/*', 'ANY'],
        ],
    ],
];
