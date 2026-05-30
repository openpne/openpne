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
            new RouteMap('diary_show', '/diary/:id', 'diary.show'),
            new RouteMap('diary_list_mine', '/diary/listMember', 'diary.list_member'),
            new RouteMap('diary_list_member', '/diary/listMember/:id', 'diary.list_member',
                note: 'Served by the same diary.list_member route (optional {member?}) as diary_list_mine.'),
            new RouteMap('diary_new', '/diary/new', 'diary.new'),
            new RouteMap('diary_create', '/diary/create', 'diary.store'),
            new RouteMap('diary_edit', '/diary/edit/:id', 'diary.edit'),
            new RouteMap('diary_update', '/diary/update/:id', 'diary.update'),
            new RouteMap('diary_delete_confirm', '/diary/deleteConfirm/:id', 'diary.delete.show'),
            new RouteMap('diary_delete', '/diary/delete/:id', 'diary.delete'),
        ];
    }

    public function gaps(): array
    {
        return [
            'diary_index' => 'Diary top (/diary) is not ported.',
            'diary_search' => 'Diary search is not ported.',
            'diary_list' => 'All-member diary feed is not ported.',
            'diary_list_friend' => 'Friend diary feed is not ported.',
            'diary_list_member_year_month' => 'Calendar archive (year/month) is not ported.',
            'diary_list_member_year_month_day' => 'Calendar archive (year/month/day) is not ported.',
            'diary_comment_history' => 'Comment history is not ported.',
            'diary_comment_create' => 'Diary comments are not ported.',
            'diary_comment_delete_confirm' => 'Diary comments are not ported.',
            'diary_comment_delete' => 'Diary comments are not ported.',
        ];
    }
}
