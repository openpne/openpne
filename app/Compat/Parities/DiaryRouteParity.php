<?php

namespace App\Compat\Parities;

use App\Compat\RouteMap;
use App\Compat\RouteParity;

class DiaryRouteParity extends RouteParity
{
    protected string $module = 'diary';

    public function maps(): array
    {
        return [
            new RouteMap('diary_show', '/diary/:id', 'diary.show', 'GET', op3Action: 'show'),
            new RouteMap('diary_list', '/diary/list', 'diary.list', 'GET', op3Action: 'list'),
            new RouteMap('diary_list_friend', '/diary/listFriend', 'diary.list_friend', 'GET', op3Action: 'listFriend'),
            new RouteMap('diary_list_mine', '/diary/listMember', 'diary.list_member', 'GET', op3Action: 'listMember'),
            new RouteMap('diary_list_member', '/diary/listMember/:id', 'diary.list_member', 'GET',
                note: 'Served by the same diary.list_member route (optional {member?}) as diary_list_mine.',
                op3Action: 'listMember'),
            // Calendar archive: same listMember action narrowed to a month/day window. The
            // clickable calendar-navigation widget is a deferred Level 2 enhancement.
            new RouteMap('diary_list_member_year_month', '/diary/listMember/:id/:year/:month', 'diary.list_member.archive', 'GET', op3Action: 'listMember'),
            new RouteMap('diary_list_member_year_month_day', '/diary/listMember/:id/:year/:month/:day', 'diary.list_member.archive', 'GET', op3Action: 'listMember'),
            new RouteMap('diary_new', '/diary/new', 'diary.new', 'GET', op3Action: 'new'),
            new RouteMap('diary_create', '/diary/create', 'diary.store', 'POST'),
            new RouteMap('diary_edit', '/diary/edit/:id', 'diary.edit', 'GET', op3Action: 'edit'),
            new RouteMap('diary_update', '/diary/update/:id', 'diary.update', 'POST'),
            new RouteMap('diary_delete_confirm', '/diary/deleteConfirm/:id', 'diary.delete.show', 'GET', op3Action: 'deleteConfirm'),
            new RouteMap('diary_delete', '/diary/delete/:id', 'diary.delete', 'POST'),

            // diaryComment module: rendered under page_diaryComment_* (op3Module override).
            new RouteMap('diary_comment_create', '/diary/:id/comment/create', 'diary.comment.store', 'POST'),
            new RouteMap('diary_comment_delete_confirm', '/diary/comment/deleteConfirm/:id', 'diary.comment.delete.show', 'GET',
                op3Action: 'deleteConfirm', op3Module: 'diaryComment'),
            new RouteMap('diary_comment_delete', '/diary/comment/delete/:id', 'diary.comment.delete', 'POST'),
        ];
    }

    public function gaps(): array
    {
        return [
            'diary_index' => 'Diary top (/diary) is not ported.',
            'diary_search' => 'Diary search is not ported.',
            // Comment create/delete are ported (above) on both surfaces. Still deferred within
            // comments: image attachments, notifications, unread tracking, thread pagination, and
            // this history feed.
            'diary_comment_history' => 'Comment history feed is not ported.',
        ];
    }
}
