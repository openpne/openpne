<?php

/**
 * OpenPNE 3 `module/action` → route name, for the navigation upgrade's uri normalization.
 *
 * OpenPNE 3 navigation rows store a link either as a route name (`@homepage`), an already-formed
 * URL (`/path`), or this bare `module/action` form. A `module/action` does not name a URL on its
 * own — symfony resolves it to the first route whose module/action matches and whose required
 * params are satisfiable. That makes it context-dependent: with a localNav subject id present the
 * id-bearing route wins, without one the id-less route does. This map records that split per pair,
 * and `App\Upgrade\Steps\NavigationUpgrade` resolves each route name to its URL through the route
 * inventory (openpne3-pc-frontend-routes.php) so the URL stays single-sourced there.
 *
 *   'module/action' => ['no_id' => '<route name>', 'with_id' => '<route name>']
 *
 * `no_id` is used for the global/own-page contexts, `with_id` for the friend/community contexts
 * (a pair with only `no_id` still resolves there, falling back to its id-less URL with `?id=`
 * appended at render). A pair absent here, or whose action belongs to a module that disabled
 * OpenPNE 3's global `/:module/:action` fallback, is left unresolved by the upgrade.
 */

return [
    // member (global fallback on: un-named actions were URL-reachable in OpenPNE 3)
    'member/index' => ['no_id' => 'member_index'],
    'member/profile' => ['no_id' => 'member_profile_mine', 'with_id' => 'member_profile'],
    'member/search' => ['no_id' => 'member_search'],
    'member/editProfile' => ['no_id' => 'member_editProfile'],
    'member/config' => ['no_id' => 'member_config'],
    'member/invite' => ['no_id' => 'member_invite'],
    'member/logout' => ['no_id' => 'member_logout'],

    // friend
    'friend/list' => ['no_id' => 'friend_list'],
    'friend/manage' => ['no_id' => 'friend_manage'],

    // diary (the plugin's fixtures use diary/index and diary/listMember)
    'diary/index' => ['no_id' => 'diary_index'],
    'diary/list' => ['no_id' => 'diary_list'],
    'diary/listMember' => ['no_id' => 'diary_list_mine', 'with_id' => 'diary_list_member'],
    'diary/listFriend' => ['no_id' => 'diary_list_friend'],
    'diary/search' => ['no_id' => 'diary_search'],
    'diary/new' => ['no_id' => 'diary_new'],

    // community
    'community/joinList' => ['no_id' => 'community_joinlist'],
    'community/search' => ['no_id' => 'community_search'],
    'community/home' => ['with_id' => 'community_home'],
    'community/join' => ['no_id' => 'community_join'],
    'community/quit' => ['no_id' => 'community_quit'],

    // communityTopic / communityEvent (the plugin's fixtures use listCommunity)
    'communityTopic/listCommunity' => ['with_id' => 'communityTopic_list_community'],
    'communityTopic/search' => ['no_id' => 'communityTopic_search_all', 'with_id' => 'communityTopic_search'],
    'communityEvent/listCommunity' => ['with_id' => 'communityEvent_list_community'],

    // message (the PC default nav stores message/index, which forwards to the inbox). Without this
    // it would stay verbatim and NavigationUri would hide the link. The friend-context
    // message/sendToFriend (compose) resolves once the write surface lands.
    'message/index' => ['no_id' => 'receiveList'],
];
