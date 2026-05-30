<?php

/**
 * OpenPNE 3 pc_frontend route inventory, by module — the route-parity SSoT the Classic
 * adapters map from. See database/parity/README.md for how to regenerate.
 *
 * Each module lists its named routes (name => [URL pattern, HTTP methods]) from
 * `php symfony app:routes pc_frontend`. OpenPNE 3 also has a global deprecated fallback
 * `/:module/:action/*` (opSymfonyDefaultRouteCollection), so by default any module action
 * is reachable by URL even without a named route. `disables_global_fallback` records that a
 * module turns this off — opDiaryPlugin adds diary_nodefaults (`/diary/*` → default/error),
 * which makes the named routes the complete set of reachable diary URLs.
 */

return [
    'diary' => [
        'disables_global_fallback' => true,
        'routes' => [
            'diary_index' => ['/diary', 'ANY'],
            'diary_search' => ['/diary/search', 'ANY'],
            'diary_list' => ['/diary/list', 'ANY'],
            'diary_list_mine' => ['/diary/listMember', 'ANY'],
            'diary_list_member' => ['/diary/listMember/:id', 'GET'],
            'diary_list_member_year_month' => ['/diary/listMember/:id/:year/:month', 'GET'],
            'diary_list_member_year_month_day' => ['/diary/listMember/:id/:year/:month/:day', 'GET'],
            'diary_list_friend' => ['/diary/listFriend', 'ANY'],
            'diary_show' => ['/diary/:id', 'GET'],
            'diary_new' => ['/diary/new', 'ANY'],
            'diary_create' => ['/diary/create', 'POST'],
            'diary_edit' => ['/diary/edit/:id', 'GET'],
            'diary_update' => ['/diary/update/:id', 'POST'],
            'diary_delete_confirm' => ['/diary/deleteConfirm/:id', 'GET'],
            'diary_delete' => ['/diary/delete/:id', 'POST'],
            'diary_comment_history' => ['/diary/comment/history', 'ANY'],
            'diary_comment_create' => ['/diary/:id/comment/create', 'POST'],
            'diary_comment_delete_confirm' => ['/diary/comment/deleteConfirm/:id', 'GET'],
            'diary_comment_delete' => ['/diary/comment/delete/:id', 'POST'],
        ],
    ],
];
