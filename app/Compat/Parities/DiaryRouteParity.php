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
            new RouteMap('diary_comment_history', '/diary/comment/history', 'diary.comment.history', 'GET', op3Action: 'history', op3Module: 'diaryComment'),
            new RouteMap('diary_show', '/diary/:id', 'diary.show', 'GET', op3Action: 'show'),
            new RouteMap('diary_search', '/diary/search', 'diary.search', 'GET', op3Action: 'search'),
            // The canonical list precedes the /diary alias below: screens() keys off op3Action and
            // takes the first match, so the `list` screen must resolve to the page, not the redirect.
            new RouteMap('diary_list', '/diary/list', 'diary.list', 'GET', op3Action: 'list'),
            // OpenPNE 3 diary_index forwarded /diary to the list action (so it rendered
            // page_diary_list); OpenPNE 4 preserves the URL with a redirect to the canonical
            // /diary/list.
            new RouteMap('diary_index', '/diary', 'diary.index_compat', 'GET', op3Action: 'list'),
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

    protected function layouts(): array
    {
        // OpenPNE 3 decorate_with('layoutB') on the member-scoped diary screens (showSuccess,
        // newSuccess, editSuccess, listMemberSuccess), which carry the author/calendar sidemenu.
        // The all-diary and friend lists (diary.list/list_friend/search → the feed view) keep the
        // global default layoutC, sidemenu-less, as OpenPNE 3 did.
        return [
            'diary.show' => 'B',
            'diary.new' => 'B',
            'diary.edit' => 'B',
            'diary.list_member' => 'B',
            'diary.list_member.archive' => 'B',
        ];
    }

    public function gaps(): array
    {
        return [
            // Comment create/delete and image attachments are ported (above) on both surfaces. Still
            // deferred within comments: notifications, unread tracking, and this history feed.
        ];
    }

    public function compatRedirects(): array
    {
        return ['/diary' => 'diary.list'];
    }

    /**
     * Surface elements per OpenPNE 3 diary template, against resources/views/diary/*.blade.php.
     * Levels follow docs/internals/classic-compatibility.md; an item short of a faithful port
     * records why (a dependency, or that it is small and unblocked).
     */
    public function screens(): array
    {
        return [
            // historySuccess.php → diary/comment/history.blade.php
            'history' => [
                new ScreenElement('recentList of commented diaries by last comment', L::Two, S::Ported, "DiaryCommentUpdate getPager (viewer's subscriptions, owner comments excluded)", 'DiaryCommentHistory::paginate — one builder with the home box, so page and box cannot diverge'),
                new ScreenElement('diary link with comment count and author', L::Two, S::Ported, 'op_diary_link_to_show(diary, true, false)'),
                new ScreenElement('pager above and below', L::Two, S::Ported, 'op_include_pager_navigation ×2'),
                new ScreenElement('empty state box', L::Three, S::Ported, "op_include_box('diaryList', 'There are no diaries.')"),
            ],
            // diaryComment/deleteConfirmSuccess.php → diary/comment/delete.blade.php
            'diaryComment/deleteConfirm' => [
                new ScreenElement('box + "Delete the comment" heading', L::Two, S::Ported, 'deleteConfirmSuccess.php div.dparts.box + partsHeading h3', 'OpenPNE 3 leaves this box without an id; OpenPNE 4 names it diary_comment_delete'),
                new ScreenElement('confirm prompt', L::Three, S::Ported, "'Do you really delete this comment?'", 'OpenPNE 4 quotes the comment body in a blockquote.commentPreview above the form; OpenPNE 3 showed the question alone'),
                new ScreenElement('POST form with the CSRF token', L::One, S::Ported, "form action url_for('diary_comment_delete', \$diaryComment) + \$form[getCSRFFieldName()]"),
                new ScreenElement('Delete submit in ul.moreInfo.button', L::One, S::Ported, 'div.operation > ul.moreInfo.button > input.input_submit'),
                new ScreenElement('backLink line back to the previous page', L::Two, S::Partial, "op_include_line('backLink', link_to_function('Back to previous page', 'history.back()'))", 'OpenPNE 4 puts a Back link to the entry under the form instead, so the #backLink line a skin styles is absent'),
                new ScreenElement('comment author or entry author only, others 404', L::One, S::Ported, 'executeDeleteConfirm forward404Unless(DiaryComment::isDeletable) — comment member_id or Diary::isAuthor', 'DiaryComment::isDeletableBy'),
                new ScreenElement("friend localNav on another member's entry", L::Two, S::Ported, "opDiaryPluginActions::setNavigation sf_nav_type='friend'", 'markLocalNavSubject on the entry owner; a comment on your own entry keeps the default nav'),
                new ScreenElement('delete returns to the entry with a notice', L::Two, S::Ported, "executeDelete redirectToDiaryShow + flash 'The comment was deleted successfully.'", 'OpenPNE 3 appends &comment_count= to that URL; OpenPNE 4 returns to the bare entry'),
            ],
            // showSuccess.php + diaryComment/_list.php component
            'show' => [
                // Comment thread (diaryComment/list component). The list renders, but several of
                // its OpenPNE 3 behaviors are split out below so they stay visible as gaps.
                new ScreenElement('comment thread (author, number, delete)', L::One, S::Ported, 'include_component diaryComment/list'),
                new ScreenElement('comment thread pagination + order toggle', L::Two, S::Ported, 'diaryComment/_list pager (size, ASC/DESC, older/newer)', 'DiaryCommentThread: reversible pager, sizes 20/100, older/newer + latest/oldest toggle'),
                new ScreenElement('comment body line breaks + auto-link', L::Three, S::Ported, 'op_url_cmd(nl2br($comment->body))', 'x-user-text (BodyText); comments carry no op_decoration in OpenPNE 3'),
                new ScreenElement('comment datetime', L::Three, S::Ported, "op_format_date(comment->created_at, 'XDateTimeJaBr')", 'LocalizedDate::dateTimeLines; year / date / time stacked, as the entry dt'),
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
                new ScreenElement('diary body decoration (rich text)', L::Three, S::Ported, 'op_decoration(body)', 'Op3Text span rendering; colors validated, unbalanced tags auto-closed'),
                new ScreenElement('Japanese datetime format', L::Three, S::Ported, "nl2br(op_format_date(created_at, 'XDateTimeJaBr'))", 'LocalizedDate::dateTimeLines; year / date / time stacked in the dt column'),
                new ScreenElement('LayoutB two-column + sidemenu (author, recent diaries)', L::Two, S::Ported, "decorate_with('layoutB') + get_component('diary','sidemenu')", 'x-diary.sidemenu; author avatar links to the profile'),
                new ScreenElement('calendar archive sidemenu', L::Two, S::Ported, '_sidemenu.php Calendar_Month_Weekdays', 'DiaryCalendar month grid + prev/next month + day-archive links (MemberDiaryDays)'),
                new ScreenElement('diary images', L::Three, S::Ported, '$diary->getDiaryImagesJoinFile()', 'DiaryImage thumbnails via the shared _images partial; each fetch FilePolicy-gated by diary visibility'),
            ],
            // newSuccess.php + _form.php (PluginDiaryForm) → diary/new.blade.php
            'new' => [
                new ScreenElement('title input', L::Two, S::Ported, 'sfWidgetFormInput title'),
                new ScreenElement('visibility choice (members/friends/private)', L::One, S::Ported, 'public_flag sfWidgetFormChoice'),
                new ScreenElement('web-public (Open) visibility option', L::Two, S::Ported, 'getPublicFlags() PUBLIC_FLAG_OPEN', 'gated by SnsSettingKey::DiaryAllowWebPublic (OpenPNE 3 op_diary_plugin_use_open_diary, default on)'),
                new ScreenElement('remembered default visibility', L::Three, S::Missing, 'MemberConfigDiaryForm::PUBLIC_FLAG default', 'OpenPNE 4 hardcodes the members default'),
                new ScreenElement('rich-text body editor', L::Three, S::Partial, 'opWidgetFormRichTextareaOpenPNE', 'plain textarea + per-record Markdown toggle and live preview; the OpenPNE 3 WYSIWYG rich-text widget is a separate later stage'),
                new ScreenElement('image upload (x3)', L::Three, S::Ported, 'app_diary_is_upload_images + DiaryImageForm', 'up to PostImages::MAX_IMAGES via the shared _image_fields partial; PostImageRules validation'),
                new ScreenElement('post button', L::Two, S::Ported, 'op_include_form diaryForm button'),
                new ScreenElement('LayoutB two-column + sidemenu (author, recent diaries)', L::Two, S::Ported, "decorate_with('layoutB') + get_component('diary','sidemenu')", 'x-diary.sidemenu; author avatar links to the profile'),
                new ScreenElement('calendar archive sidemenu', L::Two, S::Ported, '_sidemenu.php Calendar_Month_Weekdays', 'DiaryCalendar month grid + prev/next month + day-archive links (MemberDiaryDays)'),
            ],
            // editSuccess.php + _form.php (shared with new) → diary/edit.blade.php
            'edit' => [
                new ScreenElement('title input', L::Two, S::Ported, 'sfWidgetFormInput title'),
                new ScreenElement('visibility choice (members/friends/private)', L::One, S::Ported, 'public_flag sfWidgetFormChoice'),
                new ScreenElement('web-public (Open) visibility option', L::Two, S::Ported, 'getPublicFlags() PUBLIC_FLAG_OPEN', 'shared diary form; gated by SnsSettingKey::DiaryAllowWebPublic'),
                new ScreenElement('rich-text body editor', L::Three, S::Partial, 'opWidgetFormRichTextareaOpenPNE', 'plain textarea + per-record Markdown toggle and live preview; the OpenPNE 3 WYSIWYG rich-text widget is a separate later stage'),
                new ScreenElement('existing image edit / delete', L::Three, S::Ported, '_formEditImage / DiaryImageForm', 'current-image thumbnails + remove_images[] checkboxes; UpdateDiary frees and refills the slots'),
                new ScreenElement('save button', L::Two, S::Ported, 'op_include_form diaryForm button'),
                new ScreenElement('delete-diary box', L::Three, S::Ported, "formDiaryDelete url_for('diary_delete_confirm')", 'GET form to the delete confirm page; the show page keeps its own delete entry'),
                new ScreenElement('LayoutB two-column + sidemenu (author, recent diaries)', L::Two, S::Ported, "decorate_with('layoutB') + get_component('diary','sidemenu')", 'x-diary.sidemenu; author avatar links to the profile'),
                new ScreenElement('calendar archive sidemenu', L::Two, S::Ported, '_sidemenu.php Calendar_Month_Weekdays', 'DiaryCalendar month grid + prev/next month + day-archive links (MemberDiaryDays)'),
            ],
            // deleteConfirmSuccess.php → diary/delete.blade.php
            'deleteConfirm' => [
                new ScreenElement('formDiaryDelete box + "Delete the %diary%" heading', L::One, S::Ported, 'deleteConfirmSuccess.php div#formDiaryDelete.dparts.box + partsHeading h3', 'Classic only — a Modern viewer is sent back to the entry, which confirms inline'),
                new ScreenElement('confirm prompt', L::Three, S::Partial, "'Do you really delete this diary?'", 'OpenPNE 4 names the entry instead (Delete ":title"?)'),
                new ScreenElement('POST form with the CSRF token', L::One, S::Ported, "form action url_for('@diary_delete?id=') + \$form[getCSRFFieldName()]"),
                new ScreenElement('Delete submit in ul.moreInfo.button', L::One, S::Ported, 'div.operation > ul.moreInfo.button > input.input_submit'),
                new ScreenElement('backLink line back to the previous page', L::Two, S::Partial, "op_include_line('backLink', link_to_function('Back to previous page', 'history.back()'))", 'OpenPNE 4 puts a Cancel link to the entry in the button list instead, so the #backLink line a skin styles is absent'),
                new ScreenElement('author only, anyone else 404s', L::One, S::Ported, 'executeDeleteConfirm forward404Unless(isDiaryAuthor())'),
                new ScreenElement("delete returns to the author's archive with a notice", L::Two, S::Ported, "executeDelete redirect('@diary_list_member?id=') + flash 'The diary was deleted successfully.'"),
            ],
            // listSuccess.php (all-member feed; the search variant shares it) → diary/feed.blade.php
            'list' => [
                new ScreenElement('feed scope: every entry open to the membership (Open included)', L::Two, S::Ported, 'getDiaryPager PUBLIC_FLAG_SNS (saving an Open diary normalizes it to public_flag=1 + is_open, which that query matches)', 'DiaryVisibilityScope::applyFeed visibility <= Members'),
                new ScreenElement('keyword search form', L::Two, S::Ported, "url_for('@diary_search')"),
                new ScreenElement('pager navigation', L::Two, S::Ported, 'op_include_pager_navigation'),
                new ScreenElement('author nickname', L::Two, S::Ported, '$diary->Member->name'),
                new ScreenElement('empty-state message', L::Three, S::Ported, 'op_include_box diaryList'),
                new ScreenElement('title + comment count', L::Two, S::Ported, 'op_diary_get_title_and_count', 'DiaryTitle: title truncated to display width 36 + "(N)"'),
                new ScreenElement('created-at datetime', L::Three, S::Ported, "op_format_date(created_at, 'XDateTimeJa')", 'LocalizedDate'),
                new ScreenElement('author thumbnail', L::Two, S::Ported, 'image_tag_sf_image(Member->getImageFilename, 76x76)', 'Member->avatar 76×76 square linking to the entry, in the rowspan photo cell; falls back to no_image.gif'),
                new ScreenElement('body excerpt', L::Two, S::Ported, 'op_truncate(op_decoration(body, true), 36, ..., 3)', 'BodyText::excerpt; single-line width 108 (OpenPNE 3 wraps to 3×36); <op:*> decoration tags stripped'),
                new ScreenElement('has-images icon', L::Three, S::Ported, 'op_diary_image_icon', 'images_count-driven icon_camera.gif, kept under the .imageIcon styling hook'),
                new ScreenElement('view-this-entry link', L::Three, S::Ported, 'listSuccess.php tr.operation link_to diary_show', 'span.moreInfo link beside the datetime'),
            ],
            // listSuccess.php again, reached by executeSearch → setTemplate('list') with $keyword set.
            'search' => [
                new ScreenElement('search form prefilled with the submitted keyword', L::One, S::Ported, 'listSuccess.php div#diarySearchFormLine GET form to @diary_search'),
                new ScreenElement('"Search Results" heading on the searchResultList band', L::Two, S::Ported, "listSuccess.php \$title = 'Search Results'", 'OpenPNE 3 leaves that band without an id; OpenPNE 4 names it diary_feed'),
                new ScreenElement('result rows shared with the all-member list', L::One, S::Ported, 'listSuccess.php ditem table (same template as the list action)', "per-row inventory is the `list` screen's — photo, %Nickname%, Title + count, Body, Created At"),
                new ScreenElement('keyword-carrying pager, 20 per page', L::Two, S::Ported, "op_include_pager_navigation(\$pager, '@diary_search?keyword='.\$keyword.'&page=%d')", 'SearchDiaries::PER_PAGE with withQueryString(), so ?keyword= survives page 2'),
                new ScreenElement('terms AND-connected over title and body', L::One, S::Ported, 'opDiaryPluginToolkit::parseKeyword + PluginDiaryTable::addSearchKeywordQuery', 'SearchDiaries::terms / applyTerms; a full-width space separates terms as OpenPNE 3 did'),
                new ScreenElement('empty keyword falls back to the recent list', L::Two, S::Ported, "executeSearch forwardUnless(\$keywords, 'diary', 'list')", 'delegates to the list feed, its body id and its @diary_list pager URL included'),
                new ScreenElement('visibility tier by viewer (members / web-public)', L::One, S::Ported, 'executeSearch $publicFlag = isSNSMember() ? PUBLIC_FLAG_SNS : PUBLIC_FLAG_OPEN', 'DiaryVisibilityScope::applyFeed; the route stays guest-reachable (security.yml search: is_secure false)'),
                new ScreenElement('no-match message naming the keyword', L::Three, S::Missing, "op_include_box('diaryList', 'Your search \"%1%\" did not match any diaries.')", 'OpenPNE 4 prints the generic empty-feed line; the diaryList box id and the "Search Results" title match'),
                new ScreenElement('search window limited to the last N days', L::Two, S::Deferred, 'getDiarySearchPager op_diary_plugin_search_period_enable / op_diary_plugin_search_period', 'waits on the OpenPNE 3 sns_config diary-search keys reaching SnsSettingKey; OpenPNE 4 searches the whole archive'),
                new ScreenElement('site-wide search switch', L::Two, S::Deferred, 'executeSearch forward404Unless(SnsConfig op_diary_plugin_search_enable) + $isSearchEnable around the form line', 'same sns_config dependency; OpenPNE 4 always serves the screen and always renders the form'),
            ],
            // listFriendSuccess.php → diary/feed.blade.php (variant=friends, no search form)
            'listFriend' => [
                new ScreenElement('pager navigation', L::Two, S::Ported, 'op_include_pager_navigation'),
                new ScreenElement('author nickname', L::Two, S::Ported, 'op_diary_link_to_show withName'),
                new ScreenElement('empty-state message', L::Three, S::Ported, 'op_include_box diaryList'),
                new ScreenElement('per-entry title + comment count', L::Two, S::Ported, 'op_diary_get_title_and_count', 'DiaryTitle: title truncated to display width 36 + "(N)"'),
                new ScreenElement('created-at datetime', L::Three, S::Ported, "op_format_date(created_at, 'XDateTimeJa')", 'LocalizedDate'),
                new ScreenElement('has-images icon', L::Three, S::Ported, 'op_diary_image_icon', 'images_count-driven icon_camera.gif, kept under the .imageIcon styling hook'),
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
