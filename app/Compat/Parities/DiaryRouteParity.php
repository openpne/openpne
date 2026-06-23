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
            // clickable calendar-navigation widget lives in the sidemenu (x-diary.sidemenu).
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
            // Comment create/delete and image attachments are ported (above) on both surfaces. Still
            // deferred within comments: notifications, unread tracking, and this history feed.
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
                new ScreenElement('comment thread pagination + order toggle', L::Two, S::Ported, 'diaryComment/_list pager (size, ASC/DESC, older/newer)', 'DiaryCommentThread: reversible pager, sizes 20/100, older/newer + latest/oldest toggle'),
                new ScreenElement('comment body line breaks + auto-link', L::Three, S::Ported, 'op_url_cmd(nl2br($comment->body))', 'x-user-text (BodyText); comments carry no op_decoration in OpenPNE 3'),
                new ScreenElement('comment datetime', L::Three, S::Ported, "op_format_date(comment->created_at, 'XDateTimeJaBr')", 'LocalizedDate; inline single-line'),
                new ScreenElement('comment images', L::Three, S::Ported, '$comment->getDiaryCommentImagesJoinFile()', 'DiaryCommentImage thumbnails via the shared _images partial; FilePolicy-gated by the diary visibility'),
                // Comment post form. Text posting + the web-public notice are faithful; the OpenPNE 3
                // form is multipart and embeds photo fields, which is a separate deferred element.
                new ScreenElement('comment post form + is_open notice', L::One, S::Ported, 'op_include_form formDiaryComment'),
                new ScreenElement('comment image upload', L::Three, S::Ported, 'formDiaryComment isMultipart + DiaryCommentImageForm x3', 'up to PostImages::MAX_IMAGES via the shared _image_fields partial; PostImageRules validation'),
                // Diary record.
                new ScreenElement('owner edit entry', L::One, S::Ported, "operation form url_for('diary_edit')"),
                new ScreenElement('visibility label', L::Two, S::Ported, '$diary->getPublicFlagLabel()'),
                new ScreenElement('previous / next diary links', L::Two, S::Ported, '$diary->getPrevious/getNext($myMemberId)', 'AdjacentDiaries: author timeline, adjacent by id, viewer-scoped'),
                new ScreenElement("link to the member's diary list", L::Two, S::Ported, 'lineLinkToDiaryMemberList'),
                new ScreenElement('diary body line breaks + auto-link', L::Two, S::Ported, 'op_url_cmd(nl2br(body))', 'x-user-text (BodyText)'),
                new ScreenElement('diary body decoration (rich text)', L::Three, S::Partial, 'op_decoration(body)', 'pairs with the rich-text editor (Partial); plain-text bodies carry no decoration markup'),
                new ScreenElement('Japanese datetime format', L::Three, S::Ported, "op_format_date(created_at, 'XDateTimeJa')", 'LocalizedDate; inline single-line (XDateTimeJaBr stacks the parts for the OpenPNE 3 sidebar column)'),
                new ScreenElement('LayoutB two-column + sidemenu (author, recent diaries)', L::Two, S::Ported, "decorate_with('layoutB') + get_component('diary','sidemenu')", 'x-diary.sidemenu; author avatar links to the profile'),
                new ScreenElement('calendar archive sidemenu', L::Two, S::Ported, '_sidemenu.php Calendar_Month_Weekdays', 'DiaryCalendar month grid + prev/next month + day-archive links (MemberDiaryDays)'),
                new ScreenElement('diary images', L::Three, S::Ported, '$diary->getDiaryImagesJoinFile()', 'DiaryImage thumbnails via the shared _images partial; each fetch FilePolicy-gated by diary visibility'),
            ],
            // newSuccess.php + _form.php (PluginDiaryForm) → diary/new.blade.php
            'new' => [
                new ScreenElement('title input', L::Two, S::Ported, 'sfWidgetFormInput title'),
                new ScreenElement('visibility choice (members/friends/private)', L::One, S::Ported, 'public_flag sfWidgetFormChoice'),
                new ScreenElement('web-public (Open) visibility option', L::Two, S::Ported, 'getPublicFlags() PUBLIC_FLAG_OPEN', 'gated by openpne.diary.allow_web_public (OpenPNE 3 op_diary_plugin_use_open_diary, default on)'),
                new ScreenElement('remembered default visibility', L::Three, S::Missing, 'MemberConfigDiaryForm::PUBLIC_FLAG default', 'OpenPNE 4 hardcodes the members default'),
                new ScreenElement('rich-text body editor', L::Three, S::Partial, 'opWidgetFormRichTextareaOpenPNE', 'plain textarea; OpenPNE 3 rich-text widget not ported'),
                new ScreenElement('image upload (x3)', L::Three, S::Ported, 'app_diary_is_upload_images + DiaryImageForm', 'up to PostImages::MAX_IMAGES via the shared _image_fields partial; PostImageRules validation'),
                new ScreenElement('post button', L::Two, S::Ported, 'op_include_form diaryForm button'),
                new ScreenElement('LayoutB two-column + sidemenu (author, recent diaries)', L::Two, S::Ported, "decorate_with('layoutB') + get_component('diary','sidemenu')", 'x-diary.sidemenu; author avatar links to the profile'),
                new ScreenElement('calendar archive sidemenu', L::Two, S::Ported, '_sidemenu.php Calendar_Month_Weekdays', 'DiaryCalendar month grid + prev/next month + day-archive links (MemberDiaryDays)'),
            ],
            // editSuccess.php + _form.php (shared with new) → diary/edit.blade.php
            'edit' => [
                new ScreenElement('title input', L::Two, S::Ported, 'sfWidgetFormInput title'),
                new ScreenElement('visibility choice (members/friends/private)', L::One, S::Ported, 'public_flag sfWidgetFormChoice'),
                new ScreenElement('web-public (Open) visibility option', L::Two, S::Ported, 'getPublicFlags() PUBLIC_FLAG_OPEN', 'shared diary form; gated by openpne.diary.allow_web_public'),
                new ScreenElement('rich-text body editor', L::Three, S::Partial, 'opWidgetFormRichTextareaOpenPNE', 'plain textarea; OpenPNE 3 rich-text widget not ported'),
                new ScreenElement('existing image edit / delete', L::Three, S::Ported, '_formEditImage / DiaryImageForm', 'current-image thumbnails + remove_images[] checkboxes; UpdateDiary frees and refills the slots'),
                new ScreenElement('save button', L::Two, S::Ported, 'op_include_form diaryForm button'),
                new ScreenElement('delete-diary box', L::Three, S::Missing, "formDiaryDelete url_for('diary_delete_confirm')", 'OpenPNE 4 places the delete entry on the show page instead'),
                new ScreenElement('LayoutB two-column + sidemenu (author, recent diaries)', L::Two, S::Ported, "decorate_with('layoutB') + get_component('diary','sidemenu')", 'x-diary.sidemenu; author avatar links to the profile'),
                new ScreenElement('calendar archive sidemenu', L::Two, S::Ported, '_sidemenu.php Calendar_Month_Weekdays', 'DiaryCalendar month grid + prev/next month + day-archive links (MemberDiaryDays)'),
            ],
            // listSuccess.php (all-member feed; the search variant shares it) → diary/feed.blade.php
            'list' => [
                new ScreenElement('keyword search form', L::Two, S::Ported, "url_for('@diary_search')"),
                new ScreenElement('pager navigation', L::Two, S::Ported, 'op_include_pager_navigation'),
                new ScreenElement('author nickname', L::Two, S::Ported, '$diary->Member->name'),
                new ScreenElement('empty-state message', L::Three, S::Ported, 'op_include_box diaryList'),
                new ScreenElement('title + comment count', L::Two, S::Ported, 'op_diary_get_title_and_count', 'DiaryTitle: title truncated to display width 36 + "(N)"'),
                new ScreenElement('created-at datetime', L::Three, S::Ported, "op_format_date(created_at, 'XDateTimeJa')", 'LocalizedDate'),
                new ScreenElement('author thumbnail', L::Two, S::Ported, 'image_tag_sf_image(Member->getImageFilename, 76x76)', 'Member->avatar 76×76 square linking to the entry; rendered only when set (OpenPNE 4 has no default-avatar placeholder)'),
                new ScreenElement('body excerpt', L::Two, S::Ported, 'op_truncate(op_decoration(body, true), 36, ..., 3)', 'BodyText::excerpt; single-line width 108 (OpenPNE 3 wraps to 3×36); <op:*> decoration tags stripped'),
                new ScreenElement('has-images icon', L::Three, S::Ported, 'op_diary_image_icon', 'images_count-driven .imageIcon marker (OpenPNE 4 ships no camera gif, so a CSS-hook span)'),
            ],
            // listFriendSuccess.php → diary/feed.blade.php (variant=friends, no search form)
            'listFriend' => [
                new ScreenElement('pager navigation', L::Two, S::Ported, 'op_include_pager_navigation'),
                new ScreenElement('author nickname', L::Two, S::Ported, 'op_diary_link_to_show withName'),
                new ScreenElement('empty-state message', L::Three, S::Ported, 'op_include_box diaryList'),
                new ScreenElement('per-entry title + comment count', L::Two, S::Ported, 'op_diary_get_title_and_count', 'DiaryTitle: title truncated to display width 36 + "(N)"'),
                new ScreenElement('created-at datetime', L::Three, S::Ported, "op_format_date(created_at, 'XDateTimeJa')", 'LocalizedDate'),
                new ScreenElement('has-images icon', L::Three, S::Ported, 'op_diary_image_icon', 'images_count-driven .imageIcon marker (OpenPNE 4 ships no camera gif, so a CSS-hook span)'),
            ],
            // listMemberSuccess.php → diary/list.blade.php
            'listMember' => [
                new ScreenElement('owner post-diary link', L::Two, S::Ported, 'op_include_box link_to(diary_new)'),
                new ScreenElement('pager navigation', L::Two, S::Ported, 'op_include_pager_navigation'),
                new ScreenElement('archive period heading', L::Two, S::Ported, '$title .= op_format_date(...XCalendarMonth)'),
                new ScreenElement('per-entry title + comment count', L::Two, S::Ported, 'op_diary_link_to_show', 'DiaryTitle: title truncated to display width 36 + "(N)"'),
                new ScreenElement('created-at datetime', L::Three, S::Ported, "op_format_date(created_at, 'XDateTimeJa')", 'LocalizedDate'),
                new ScreenElement('LayoutB two-column + sidemenu (author, recent diaries)', L::Two, S::Ported, "decorate_with('layoutB') + get_component('diary','sidemenu')", 'x-diary.sidemenu; author avatar links to the profile'),
                new ScreenElement('calendar archive sidemenu', L::Two, S::Ported, '_sidemenu.php Calendar_Month_Weekdays', 'DiaryCalendar month grid + clickable month/day archive nav (MemberDiaryDays)'),
            ],
        ];
    }
}
