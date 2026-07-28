<?php

namespace App\Compat\Parities;

use App\Compat\CompatLevel as L;
use App\Compat\RouteMap;
use App\Compat\RouteParity;
use App\Compat\ScreenElement;
use App\Compat\ScreenStatus as S;

class CommunityEventRouteParity extends RouteParity
{
    protected string $module = 'communityEvent';

    public function maps(): array
    {
        return [
            new RouteMap('communityEvent_list_community', '/communityEvent/listCommunity/:id', 'communityEvent.index', 'GET', op3Action: 'listCommunity'),
            new RouteMap('communityEvent_new', '/communityEvent/new/:id', 'communityEvent.new', 'GET', op3Action: 'new'),
            new RouteMap('communityEvent_create', '/communityEvent/create/:id', 'communityEvent.store', 'POST'),
            new RouteMap('communityEvent_show', '/communityEvent/:id', 'communityEvent.show', 'GET', op3Action: 'show'),
            new RouteMap('communityEvent_edit', '/communityEvent/edit/:id', 'communityEvent.edit', 'GET', op3Action: 'edit'),
            new RouteMap('communityEvent_update', '/communityEvent/update/:id', 'communityEvent.update', 'POST'),
            new RouteMap('communityEvent_delete_confirm', '/communityEvent/deleteConfirm/:id', 'communityEvent.delete.show', 'GET', op3Action: 'deleteConfirm'),
            new RouteMap('communityEvent_delete', '/communityEvent/delete/:id', 'communityEvent.delete', 'POST'),
            new RouteMap('communityEvent_memberList', '/communityEvent/:id/memberList', 'communityEvent.member_list', 'GET', op3Action: 'memberList'),

            // communityEventComment module: rendered under page_communityEventComment_* (op3Module
            // override). create keys off the event id; deleteConfirm/delete key off the comment id.
            new RouteMap('communityEvent_comment_create', '/communityEvent/:id/comment/create', 'communityEvent.comment.store', 'POST'),
            new RouteMap('communityEvent_comment_delete_confirm', '/communityEvent/comment/deleteConfirm/:id', 'communityEvent.comment.delete.show', 'GET',
                op3Action: 'deleteConfirm', op3Module: 'communityEventComment'),
            new RouteMap('communityEvent_comment_delete', '/communityEvent/comment/delete/:id', 'communityEvent.comment.delete', 'POST'),
        ];
    }

    public function gaps(): array
    {
        return [
            // Cross-community "recently updated events" feed (ordered by updated_at): a sidebar
            // widget, outside the per-community event board this adapter serves.
            'communityEvent_recently_event_list' => 'Cross-community recently-updated events feed; a sidebar widget outside the event board.',
            // Global event search. OpenPNE 3 routes it to the shared topic/event search action, a
            // separate search surface the board adapter does not provide.
            'communityEvent_search_all' => 'Global event search; routes to the shared topic/event search form, a separate surface the board adapter does not provide.',
        ];
    }

    public function acknowledgesGlobalFallback(): bool
    {
        // communityEvent_nodefaults (/communityEvent/* → default/error) disables the global
        // /:module/:action fallback, so the named routes are the complete reachable set.
        return false;
    }

    /**
     * Surface elements per opCommunityTopicPlugin communityEvent template, against
     * resources/views/community-event/*.blade.php. Levels follow
     * docs/internals/classic-compatibility.md; an item short of a faithful port records why.
     */
    public function screens(): array
    {
        return [
            // listCommunitySuccess.php → community-event/index.blade.php
            'listCommunity' => [
                new ScreenElement('event list (dl: last-activity datetime / name(count) link)', L::Two, S::Ported, 'listCommunitySuccess.php recentList dl > dt + dd', 'OpenPNE 4 trails the link with the open date, prints an empty-state message where OpenPNE 3 dropped the whole box, and closes the page with a line box back to the community'),
                new ScreenElement('create-event entry', L::Two, S::Partial, "op_include_parts('buttonBox', 'communityEventList', button Create)", 'folded into the board box as a moreInfo link; OpenPNE 3 gave it its own box with a submit button'),
                new ScreenElement('pager navigation (above and below)', L::Two, S::Ported, "op_include_pager_navigation(\$pager, '@communityEvent_list_community')"),
                new ScreenElement('box heading', L::Three, S::Partial, 'listCommunitySuccess.php <h3>List of events</h3>', 'headed with the community name instead'),
            ],
            // showSuccess.php + communityEventComment/_list.php → community-event/show.blade.php.
            // The comment delete confirm shares this action key (op3Module communityEventComment),
            // so its elements live at the end of the comment group below.
            'show' => [
                new ScreenElement('event listBox', L::One, S::Ported, "op_include_parts('listBox', 'communityEvent')", 'the bare id communityEvent is OpenPNE 3\'s own, restored verbatim'),
                new ScreenElement('box heading "[community] Event"', L::Three, S::Partial, "showSuccess.php '['.\$community->getName().'] '.__('Event')", 'headed with the event name, so the owning community is no longer named there'),
                new ScreenElement('writer row', L::Two, S::Ported, "\$list['Writer'] op_community_topic_link_to_member"),
                new ScreenElement('name row', L::Two, S::Missing, "\$list['Name'] = \$communityEvent->getName()", 'the name moved into the box heading, so the detail table has no Name row'),
                new ScreenElement('open date + supplement row', L::Two, S::Ported, "\$list['Open date'] op_format_date('D') . getOpenDateComment()"),
                new ScreenElement('area row (auto-link)', L::Two, S::Ported, "\$list['Area'] op_url_cmd(\$communityEvent->getArea())", 'x-user-text (BodyText), so a bare venue URL still autolinks'),
                new ScreenElement('body row (images + line breaks + auto-link)', L::Two, S::Partial, "\$list['Body'] ul.photo + nl2br(\$communityEvent->getBody())", 'rendered as a div.eventBody after the table, so opCommunityTopicPlugin\'s `#communityEvent table ul.photo` rules match nothing'),
                new ScreenElement('application deadline row', L::Two, S::Ported, "\$list['Application deadline'] op_format_date('D')"),
                new ScreenElement('capacity row', L::Two, S::Ported, "\$list['Capacity'] = \$communityEvent->getCapacity()"),
                new ScreenElement('participant count row + member-list link', L::Two, S::Ported, "\$list['Count of Member'] + link_to('@communityEvent_memberList')"),
                new ScreenElement('edit entry', L::Two, S::Partial, 'showSuccess.php div.operation > form > ul.button input Edit', 'a text link pair (Edit / Delete); OpenPNE 3 had a centred submit button and reached deletion from the edit screen only'),
                new ScreenElement('comment thread (number, author, delete)', L::One, S::Ported, "include_component('communityEventComment', 'list')"),
                new ScreenElement('comment pagination + order toggle', L::Two, S::Ported, '_list.php op_include_pager_navigation (reversible)', 'CommunityEventCommentThread: fixed size 20, older/newer + latest/oldest toggle'),
                new ScreenElement('comment datetime', L::Three, S::Ported, "nl2br(op_format_date(created_at, 'XDateTimeJaBr'))", 'LocalizedDate; inline single-line'),
                new ScreenElement('comment images', L::Three, S::Ported, '_list.php $comment->getImages() ul.photo'),
                new ScreenElement('comment body line breaks + auto-link', L::Three, S::Ported, 'op_url_cmd(nl2br($comment->getBody()))', 'x-user-text (BodyText)'),
                new ScreenElement('reply (>>N) quote link', L::Three, S::Missing, '_list.php a.reply + SnsConfig op_community_topic_plugin_community_topic_comment_reply', 'the link that prepends ">>N name" into the comment textarea is not ported'),
                new ScreenElement('RSVP buttons in the comment form', L::One, S::Ported, 'showSuccess.php input name=participate / cancel / comment, gated by isClosed, isExpired, isEventMember, isAtCapacity'),
                new ScreenElement('comment post form + image upload', L::One, S::Ported, 'showSuccess.php hand-written <form> around a lone .parts.form', 'up to PostImages::MAX_IMAGES on one Images row; OpenPNE 3 gave each photo its own labelled row'),
                new ScreenElement('required-field notice', L::Three, S::Missing, 'showSuccess.php hand-written "%0% is required field." line', 'the hand-written OpenPNE 3 comment form prints the notice without per-label markers; OpenPNE 4 drops it and marks the inputs HTML required'),
                new ScreenElement('comment delete confirmation', L::Two, S::Ported, "communityEventComment/deleteConfirmSuccess.php op_include_form('deleteConfirmForm')", 'a screen of its own, folded here because it shares the deleteConfirm action key. OpenPNE 4 adds the question line and a blockquote.commentPreview, and replaces the history.back() line with a Back link'),
                new ScreenElement('community top-page line link', L::Two, S::Partial, "op_include_line('linkLine', link_to('community/home'))", 'links to the event board, not the community home, and drops the "[name] %Community% Top Page" label'),
            ],
            // newSuccess.php (PluginCommunityEventForm) → community-event/new.blade.php
            'new' => [
                new ScreenElement('title input', L::Two, S::Ported, 'PluginCommunityEventForm name sfWidgetFormInput'),
                new ScreenElement('body textarea', L::Two, S::Ported, 'BaseCommunityEventForm body', 'OpenPNE 4 adds the shared body-format toggle'),
                new ScreenElement('open date', L::Two, S::Partial, 'open_date opWidgetFormDate (month_format number, can_be_empty)', 'a native date input; OpenPNE 3 split it into year / month / day selects'),
                new ScreenElement('open-date supplement input', L::Two, S::Partial, 'open_date_comment sfWidgetFormInput', 'shares the open-date row as a placeholder-only field; OpenPNE 3 gave it its own labelled row'),
                new ScreenElement('area input', L::Two, S::Ported, 'area sfWidgetFormInput'),
                new ScreenElement('application deadline', L::Two, S::Partial, 'application_deadline opWidgetFormDate', 'a native date input; OpenPNE 3 split it into year / month / day selects'),
                new ScreenElement('capacity input', L::Two, S::Ported, 'capacity sfValidatorInteger (min 0)'),
                new ScreenElement('image upload (×3)', L::Two, S::Partial, 'photo_1..3 embedded opCommunityTopicPluginImageForm, ul#community_event_photo_N', 'one Images row with MAX_IMAGES file inputs; OpenPNE 3 gave each photo its own labelled row inside a ul#community_event_photo_N'),
                new ScreenElement('field order', L::Three, S::Partial, 'PluginCommunityEventForm setup() order: title, body, open date, supplement, area, deadline, capacity', 'OpenPNE 4 orders title, open date, area, body, deadline, capacity'),
                new ScreenElement('post button', L::Two, S::Ported, "op_include_form('formCommunityEvent') button", 'relabelled from Send, with a Cancel link beside it'),
                new ScreenElement('required-field markers', L::Three, S::Missing, "_partsForm.php mark_required_field + '%0% is required field.'", 'no per-label * marker and no notice line; the inputs carry the HTML required attribute instead'),
            ],
            // editSuccess.php (same form as new) → community-event/edit.blade.php
            'edit' => [
                new ScreenElement('event fields (title, body, open date + supplement, area, deadline, capacity)', L::Two, S::Partial, 'editSuccess.php formCommunityEvent (the create form)', 'one shared partial with the create screen, so its widget differences apply here too'),
                new ScreenElement('existing image edit / delete', L::Two, S::Partial, 'communityTopic/_formEditImage.php (thumbnail + %input% + %delete%)', 'a current-images list with remove_images[] checkboxes; OpenPNE 3 let each slot be replaced in place'),
                new ScreenElement('new image upload (×3)', L::Two, S::Partial, 'photo_1..3 embedded opCommunityTopicPluginImageForm', 'one Images row with MAX_IMAGES file inputs; OpenPNE 3 gave each photo its own labelled row'),
                new ScreenElement('save button', L::Two, S::Ported, "op_include_form('formCommunityEvent') button", 'relabelled from Send, with a Cancel link beside it'),
                new ScreenElement('delete-event box', L::Two, S::Ported, "op_include_parts('buttonBox', 'toDelete')", 'GET form to the delete confirm page'),
                new ScreenElement('required-field markers', L::Three, S::Missing, "_partsForm.php mark_required_field + '%0% is required field.'", 'no per-label * marker and no notice line; the inputs carry the HTML required attribute instead'),
            ],
            // deleteConfirmSuccess.php → community-event/delete.blade.php
            'deleteConfirm' => [
                new ScreenElement('delete confirmation form', L::One, S::Ported, "op_include_form('deleteConfirmForm', \$form, title)", 'OpenPNE 4 adds the question paragraph OpenPNE 3 left to the box title'),
                new ScreenElement('back-to-previous line', L::Three, S::Partial, "op_include_line('backLink', link_to_function(history.back()))", 'a Cancel link to the event instead of the JavaScript line box'),
            ],
            // memberListSuccess.php / memberListError.php → community-event/member-list.blade.php
            'memberList' => [
                new ScreenElement('participant grid (avatar, name + friend count)', L::Two, S::Ported, "op_include_parts('photoTable', 'communityEventMembersList')", 'x-classic.photo-table; getNameAndCount() renders as "name (N)"'),
                new ScreenElement('pager navigation', L::Two, S::Ported, 'photoTable pager + link_to_pager'),
                new ScreenElement('empty-list box', L::Three, S::Ported, "memberListError.php op_include_box('noMembers', 'Nobody joins this event.')"),
                new ScreenElement('back-to-previous line', L::Three, S::Partial, "memberListError.php op_include_line('backLink', link_to_function(history.back()))", 'a line box linking back to the event, on both the populated and the empty state; OpenPNE 3 had the JavaScript line on the empty state only'),
            ],
        ];
    }
}
