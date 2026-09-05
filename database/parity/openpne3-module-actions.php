<?php

/**
 * OpenPNE 3 `module/action` → route name, split `no_id` / `with_id` because symfony resolved one
 * pair to different routes by context. A pair absent here, or whose module disabled OpenPNE 3's
 * global `/:module/:action` fallback, is left unresolved by the upgrade.
 */

return [
    'member/index' => ['no_id' => 'member_index'],
    'member/profile' => ['no_id' => 'member_profile_mine', 'with_id' => 'member_profile'],
    'member/search' => ['no_id' => 'member_search'],
    'member/editProfile' => ['no_id' => 'member_editProfile'],
    'member/config' => ['no_id' => 'member_config'],
    'member/invite' => ['no_id' => 'member_invite'],
    'member/logout' => ['no_id' => 'member_logout'],

    'friend/list' => ['no_id' => 'friend_list'],
    'friend/manage' => ['no_id' => 'friend_manage'],

    'diary/index' => ['no_id' => 'diary_index'],
    'diary/list' => ['no_id' => 'diary_list'],
    'diary/listMember' => ['no_id' => 'diary_list_mine', 'with_id' => 'diary_list_member'],
    'diary/listFriend' => ['no_id' => 'diary_list_friend'],
    'diary/search' => ['no_id' => 'diary_search'],
    'diary/new' => ['no_id' => 'diary_new'],

    'community/joinList' => ['no_id' => 'community_joinlist'],
    'community/search' => ['no_id' => 'community_search'],
    'community/home' => ['with_id' => 'community_home'],
    'community/join' => ['no_id' => 'community_join'],
    'community/quit' => ['no_id' => 'community_quit'],

    'communityTopic/listCommunity' => ['with_id' => 'communityTopic_list_community'],
    'communityTopic/search' => ['no_id' => 'communityTopic_search_all', 'with_id' => 'communityTopic_search'],
    'communityEvent/listCommunity' => ['with_id' => 'communityEvent_list_community'],

    // sendToFriend has no named OpenPNE 3 route, so its value is a literal URL (leading `/`) the
    // upgrade uses directly instead of an inventory route-name lookup.
    'message/index' => ['no_id' => 'receiveList'],
    'message/sendToFriend' => ['with_id' => '/message/sendToFriend?id=:id'],
];
