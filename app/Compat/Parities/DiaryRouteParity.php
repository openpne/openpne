<?php

namespace App\Compat\Parities;

use App\Compat\CompatLevel as L;
use App\Compat\RouteMap;
use App\Compat\RouteParity;
use App\Compat\ScreenElement;
use App\Compat\ScreenStatus as S;

class DiaryRouteParity extends RouteParity
{
    protected string $module = 'diary';

    public function maps(): array
    {
        return [
            new RouteMap('diary_show', '/diary/:id', 'diary.show', 'GET', op3Action: 'show'),
            new RouteMap('diary_search', '/diary/search', 'diary.search', 'GET', op3Action: 'search'),
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
            // Comment create/delete are ported (above) on both surfaces. Still deferred within
            // comments: image attachments, notifications, unread tracking, thread pagination, and
            // this history feed.
            'diary_comment_history' => 'Comment history feed is not ported.',
        ];
    }

    /**
     * Surface elements per OpenPNE 3 diary template, against resources/views/diary/*.blade.php.
     * Levels follow docs/internals/classic-compatibility.md; an item short of a faithful port
     * records why (a dependency, or that it is small and unblocked).
     */
    public function screens(): array
    {
        return [
            // showSuccess.php + diaryComment/_list.php component
            'show' => [
                // Comment thread (diaryComment/list component). The list renders, but several of
                // its OpenPNE 3 behaviours are split out below so they stay visible as gaps.
                new ScreenElement('comment thread (author, number, delete)', L::One, S::Ported, 'include_component diaryComment/list'),
                new ScreenElement('comment thread pagination + order toggle', L::Two, S::Missing, 'diaryComment/_list pager (size, ASC/DESC, older/newer)', 'all comments shown unpaginated; OpenPNE 3 pages with a size and latest/oldest toggle'),
                new ScreenElement('comment body auto-link', L::Three, S::Partial, 'op_url_cmd(nl2br($comment->body))', 'comment body shown raw; auto-link/nl2br not ported'),
                new ScreenElement('comment images', L::Three, S::Deferred, '$comment->getDiaryCommentImagesJoinFile()', 'image delivery not built (FileStorage)'),
                // Comment post form. Text posting + the web-public notice are faithful; the OpenPNE 3
                // form is multipart and embeds photo fields, which is a separate deferred element.
                new ScreenElement('comment post form + is_open notice', L::One, S::Ported, 'op_include_form formDiaryComment'),
                new ScreenElement('comment image upload', L::Three, S::Deferred, 'formDiaryComment isMultipart + DiaryCommentImageForm x3', 'OpenPNE 3 embeds up to 3 photo fields; image delivery not built'),
                // Diary record.
                new ScreenElement('owner edit entry', L::One, S::Ported, "operation form url_for('diary_edit')"),
                new ScreenElement('visibility label', L::Two, S::Missing, '$diary->getPublicFlagLabel()', 'small, no dependency'),
                new ScreenElement('previous / next diary links', L::Two, S::Missing, '$diary->getPrevious/getNext($myMemberId)', 'needs an owner-scoped adjacent-diary query'),
                new ScreenElement("link to the member's diary list", L::Two, S::Missing, 'lineLinkToDiaryMemberList', 'small, no dependency'),
                new ScreenElement('diary body auto-link + decoration', L::Two, S::Partial, 'op_url_cmd(op_decoration(nl2br(body)))', 'body shown raw; decoration/auto-link helpers not ported'),
                new ScreenElement('Japanese datetime format', L::Three, S::Partial, "op_format_date(created_at, 'XDateTimeJaBr')", 'currently Y-m-d H:i'),
                new ScreenElement('LayoutB + calendar sidemenu', L::Two, S::Missing, "decorate_with('layoutB') + get_component('diary','sidemenu')", 'layout-cross-cutting: Classic layout has no op_sidemenu slot yet'),
                new ScreenElement('diary images', L::Three, S::Deferred, '$diary->getDiaryImagesJoinFile()', 'image delivery not built (FileStorage)'),
            ],
            // listSuccess.php (all-member feed; the search variant shares it) → diary/feed.blade.php
            'list' => [
                new ScreenElement('keyword search form', L::Two, S::Ported, "url_for('@diary_search')"),
                new ScreenElement('pager navigation', L::Two, S::Ported, 'op_include_pager_navigation'),
                new ScreenElement('author nickname', L::Two, S::Ported, '$diary->Member->name'),
                new ScreenElement('empty-state message', L::Three, S::Ported, 'op_include_box diaryList'),
                new ScreenElement('title + comment count', L::Two, S::Partial, 'op_diary_get_title_and_count', 'title shown; comment count "(N)" not appended'),
                new ScreenElement('author thumbnail', L::Two, S::Missing, 'image_tag_sf_image(Member->getImageFilename)', 'avatar delivery'),
                new ScreenElement('body excerpt', L::Two, S::Missing, 'op_truncate(op_decoration(body))', 'no excerpt rendered'),
                new ScreenElement('has-images icon', L::Three, S::Missing, 'op_diary_image_icon', 'image delivery not built'),
            ],
            // listFriendSuccess.php → diary/feed.blade.php (variant=friends, no search form)
            'listFriend' => [
                new ScreenElement('pager navigation', L::Two, S::Ported, 'op_include_pager_navigation'),
                new ScreenElement('author nickname', L::Two, S::Ported, 'op_diary_link_to_show withName'),
                new ScreenElement('empty-state message', L::Three, S::Ported, 'op_include_box diaryList'),
                new ScreenElement('per-entry title + comment count', L::Two, S::Partial, 'op_diary_get_title_and_count', 'title shown; comment count "(N)" not appended'),
                new ScreenElement('has-images icon', L::Three, S::Missing, 'op_diary_image_icon', 'image delivery not built'),
            ],
            // listMemberSuccess.php → diary/list.blade.php
            'listMember' => [
                new ScreenElement('owner post-diary link', L::Two, S::Ported, 'op_include_box link_to(diary_new)'),
                new ScreenElement('pager navigation', L::Two, S::Ported, 'op_include_pager_navigation'),
                new ScreenElement('archive period heading', L::Two, S::Ported, '$title .= op_format_date(...XCalendarMonth)'),
                new ScreenElement('per-entry title + comment count', L::Two, S::Partial, 'op_diary_link_to_show', 'comment count not shown'),
                new ScreenElement('LayoutB + calendar sidemenu', L::Two, S::Missing, "decorate_with('layoutB') + get_component('diary','sidemenu')", 'layout-cross-cutting: clickable month/day calendar archive nav'),
            ],
        ];
    }
}
