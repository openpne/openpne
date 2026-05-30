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
];
