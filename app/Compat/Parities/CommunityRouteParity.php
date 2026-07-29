<?php

namespace App\Compat\Parities;

use App\Compat\CompatLevel as L;
use App\Compat\RouteMap;
use App\Compat\RouteParity;
use App\Compat\ScreenElement;
use App\Compat\ScreenStatus as S;

class CommunityRouteParity extends RouteParity
{
    protected string $module = 'community';

    protected function layouts(): array
    {
        // OpenPNE 3 community/home is layoutA (its view.yml): the community image box and member
        // grid fill the sidemenu, the pending-approval notice the top row.
        return ['community.show' => 'A'];
    }

    public function maps(): array
    {
        return [
            new RouteMap('community_home', '/community/:id', 'community.show', 'GET', op3Action: 'home'),
            new RouteMap('community_search', '/community/search', 'community.search', 'GET', op3Action: 'search'),
            // joinList is "communities this member belongs to"; ?id= shows another member's list.
            new RouteMap('community_joinlist', '/community/joinList', 'community.list_mine', 'GET', op3Action: 'joinlist'),
            new RouteMap('community_memberList', '/community/member/list', 'community.members', 'GET', op3Action: 'memberList'),

            // OpenPNE 3 serves one /community/edit for both new and edit (id presence switches),
            // and one POST for create/update. Laravel cannot route the same method+path to two
            // named routes by query param, so each is a single endpoint.
            new RouteMap('community_edit', '/community/edit', 'community.edit', 'GET', op3Action: 'edit'),
            new RouteMap('community_edit', '/community/edit', 'community.save', 'POST'),

            // join / quit / delete: OpenPNE 3 confirms on GET and runs on POST under one route;
            // split into an explicit GET confirm + POST submit (cf. FriendRouteParity unlink).
            // When the confirm does not apply (already a member on join; the admin on quit),
            // OpenPNE 4 redirects to the community home instead of rendering the OpenPNE 3
            // confirm page (Level 3).
            new RouteMap('community_join', '/community/join', 'community.join.show', 'GET', op3Action: 'join'),
            new RouteMap('community_join', '/community/join', 'community.join', 'POST'),
            new RouteMap('community_quit', '/community/quit', 'community.quit.show', 'GET', op3Action: 'quit'),
            new RouteMap('community_quit', '/community/quit', 'community.quit', 'POST'),
            new RouteMap('community_delete', '/community/delete/:id', 'community.delete.show', 'GET', op3Action: 'delete'),
            new RouteMap('community_delete', '/community/delete/:id', 'community.delete', 'POST'),

            // The member-management page: the /community/member/manage/:id URL is preserved (path
            // param) and it borrows page_community_memberManage. The per-member operations are
            // OpenPNE 4-native (OpenPNE 3 reached them through the global fallback), so they carry no
            // named OpenPNE 3 route; the GET confirms borrow the OpenPNE 3 input-page body ids.
            // It must precede the pending queue below: both declare memberManage, and screens()
            // resolves an action to its *first* map, so the OpenPNE 3 screen has to win.
            new RouteMap('community_memberManage', '/community/member/manage/:id', 'community.members.manage', 'GET', op3Action: 'memberManage', note: 'The member-management page is fully ported: sub-admin appoint/demote, member removal, and admin-transfer request.'),
            new RouteMap(null, null, 'community.members.appoint.show', 'GET', op3Action: 'subAdminRequest', note: 'OpenPNE 3 nominated a sub-admin through a confirmation handshake; OpenPNE 4 appoints immediately (the upgrade drops pending nominations; the appointee gets a feed notification).'),
            new RouteMap(null, null, 'community.members.appoint', 'POST'),
            new RouteMap(null, null, 'community.members.demote.show', 'GET', op3Action: 'removeSubAdmin'),
            new RouteMap(null, null, 'community.members.demote', 'POST'),
            new RouteMap(null, null, 'community.members.drop.show', 'GET', op3Action: 'dropMember'),
            new RouteMap(null, null, 'community.members.drop', 'POST'),
            new RouteMap(null, null, 'community.members.transfer.show', 'GET', op3Action: 'changeAdminRequest', note: 'The nominee accepts or declines from a banner on the community home plus a feed notification — OpenPNE 4 has no confirmation center (OpenPNE 3 routed the decision through it).'),
            new RouteMap(null, null, 'community.members.transfer', 'POST'),
            new RouteMap(null, null, 'community.members.transfer.accept', 'POST'),
            new RouteMap(null, null, 'community.members.transfer.reject', 'POST'),

            // Pending-member approval. OpenPNE 3 has no named route for it (joins were approved
            // from the global confirmation centre), so these are native maps; the screen borrows
            // page_community_memberManage, the body id of the management page above.
            new RouteMap(null, null, 'community.members.pending', 'GET', op3Action: 'memberManage'),
            new RouteMap(null, null, 'community.members.approve', 'POST'),
            new RouteMap(null, null, 'community.members.decline', 'POST'),
        ];
    }

    public function gaps(): array
    {
        return [
            'community_deleteImage' => 'Top-image upload and removal are ported into the edit form (a file field plus a remove checkbox), so the standalone /community/deleteImage/:id URL is not preserved.',
        ];
    }

    public function acknowledgesGlobalFallback(): bool
    {
        // No community_nodefaults route in OpenPNE 3, so /:module/:action stays reachable
        // (mobile smt* actions, deleteImage, memberManage all reach it that way).
        return true;
    }

    /**
     * Surface elements per OpenPNE 3 apps/pc_frontend/modules/community template, against
     * resources/views/community/*.blade.php. Levels follow docs/internals/classic-compatibility.md;
     * an item short of a faithful port records why.
     */
    public function screens(): array
    {
        return [
            // homeSuccess.php (layoutA) + the six view.yml customizes opCommunityTopicPlugin and
            // opTimelinePlugin inject into it → community/show.blade.php + x-community.sidemenu
            'home' => [
                new ScreenElement('community image box (photo + name)', L::Two, S::Partial, "op_include_parts('memberImageBox', 'communityImageBox')", 'x-community.sidemenu; the thumbnail is 120×120 (OpenPNE 3: 180×180) and the caption drops getNameAndCount()\'s member count'),
                new ScreenElement('member grid (3×3, admin crown)', L::Two, S::Partial, "op_include_parts('nineTable', 'friendList', crownIds)", 'x-gadget.nine-table; the name cell drops getNameAndCount()\'s friend count. The id friendList is OpenPNE 3\'s own copy-paste name for the community grid, restored verbatim'),
                new ScreenElement('roster links (Show all(N), Management member)', L::Two, S::Ported, "op_include_parts('nineTable', … 'moreInfo')"),
                new ScreenElement('pending-approval notice', L::Two, S::Ported, "op_include_parts('descriptionBox', 'informationAboutCommunity')"),
                new ScreenElement('community details listBox (name, category, created, member count)', L::One, S::Ported, "op_include_parts('listBox', 'communityHome')"),
                new ScreenElement('administrator + sub-administrator rows', L::Two, S::Ported, '$communityAdmin / $communitySubAdmins link_to member_profile'),
                new ScreenElement('description row (line breaks + auto-link)', L::Two, S::Ported, "op_url_cmd(nl2br(getConfig('description')))", 'x-user-text (BodyText); the row renders even when empty, as OpenPNE 3 did'),
                new ScreenElement('topic read / create authority rows', L::Two, S::Ported, 'getConfigs() public_flag + topic_authority'),
                new ScreenElement('register policy row', L::Two, S::Ported, 'getRawValue()->getRegisterPolicy()'),
                new ScreenElement('membership operations (edit / join / leave)', L::One, S::Ported, 'homeSuccess.php trailing <ul> link_to community_edit / community_join / community_quit', 'OpenPNE 4 adds a Pending members entry for the administrator (OpenPNE 3 approved joins from its confirmation centre, which is not ported) and withholds Join from a pending applicant, whose OpenPNE 3 link only reached joinError.php'),
                new ScreenElement('recent topics list', L::Two, S::Partial, 'communityTopic/_communityTopicList.php tr.communityTopic ul.articleList', 'a separate community_recentTopics box with ul.topicList instead of a communityHome table row, so opCommunityTopicPlugin\'s `.communityTopic ul.articleList` bullet does not apply; the per-entry update date and the 36-char title truncation are dropped'),
                new ScreenElement('recent events list', L::Two, S::Partial, 'communityEvent/_communityEventList.php tr.communityEvent ul.articleList', 'same shape as recent topics; OpenPNE 4 shows the open date where OpenPNE 3 showed the update date'),
                new ScreenElement('opCommunityTopicPlugin stylesheet', L::Two, S::Missing, "_communityTopicList.php addStylesheet('/opCommunityTopicPlugin/css/communityTopic')", 'PluginStylesheets links plugin CSS per module and community declares none, so the home\'s topic and event boxes load unstyled; OpenPNE 3 got the link from the embedded component'),
                new ScreenElement('community timeline widget', L::Two, S::Deferred, 'timeline/_timelineCommunity.php (opTimelinePlugin homeSuccess customize)', 'post box + feed; waiting on the timeline community scope'),
                new ScreenElement('per-community notification-mail form', L::Two, S::Deferred, 'communityTopic/_configNotificationMail.php', 'OpenPNE 4 notification opt-outs are member-level, with no per-community toggle (config_community_topic_notification_mail is gapped in communityTopic)'),
                new ScreenElement('topic search form + link', L::Two, S::Missing, 'communityTopic/_topSearchForm.php + _searchFormLine.php', 'the shared topic/event search surface is not ported (communityTopic_search is gapped)'),
            ],
            // searchSuccess.php → community/search.blade.php
            'search' => [
                new ScreenElement('keyword + category search form', L::Two, S::Ported, "op_include_form('searchCommunity', \$filters, method get)"),
                new ScreenElement('create-a-community link', L::Two, S::Ported, "searchSuccess.php moreInfo link_to('@community_edit')"),
                new ScreenElement('search results (thumbnail, name / member count / description, detail link)', L::Two, S::Ported, "op_include_parts('searchResultList', 'searchCommunityResult')", 'x-classic.search-result-list; the id keeps OpenPNE 3\'s spelling'),
                new ScreenElement('pager navigation', L::Two, S::Ported, 'searchResultList pager + link_to_page'),
                new ScreenElement('empty-results box', L::Three, S::Ported, "op_include_box('searchCommunityResult', 'Your search queries did not match any %community%.')"),
                new ScreenElement('topic search link', L::Two, S::Missing, 'communityTopic/_topicSearchLink.php (searchSuccess customize)', 'the shared topic/event search surface is not ported (communityTopic_search_all is gapped)'),
            ],
            // joinlistSuccess.php / joinlistError.php → community/list.blade.php
            'joinlist' => [
                new ScreenElement('community grid (photo, name + member count)', L::Two, S::Ported, "op_include_parts('photoTable', 'communityList')", 'x-classic.photo-table; getNameAndCount() renders as "name (N)"'),
                new ScreenElement('admin crown badge', L::Three, S::Ported, 'joinlistSuccess.php crownIds'),
                new ScreenElement('pager navigation', L::Two, S::Ported, 'photoTable pager + link_to_pager'),
                new ScreenElement('empty-list box', L::Three, S::Ported, "joinlistError.php op_include_box('noJoinCommunity')"),
            ],
            // memberListSuccess.php → community/members.blade.php
            'memberList' => [
                new ScreenElement('member grid (avatar, name + friend count)', L::Two, S::Ported, "op_include_parts('photoTable', 'communityMembersList')", 'x-classic.photo-table; getNameAndCount() renders as "name (N)"'),
                new ScreenElement('admin crown badge', L::Three, S::Ported, 'memberListSuccess.php crownIds', 'the administrator only; OpenPNE 3 crowns no sub-admin here'),
                new ScreenElement('pager navigation', L::Two, S::Ported, 'photoTable pager + link_to_pager'),
            ],
            // editSuccess.php (CommunityForm + CommunityConfigForm + CommunityFileForm) →
            // community/edit.blade.php
            'edit' => [
                new ScreenElement('one form for create and edit', L::Two, S::Ported, 'editSuccess.php $communityForm->isNew() title / url switch'),
                new ScreenElement('name input', L::Two, S::Ported, 'CommunityForm name (opValidatorString max_length 64)'),
                new ScreenElement('category choice', L::Two, S::Ported, 'CommunityForm community_category_id sfWidgetFormChoice'),
                new ScreenElement('description textarea', L::Two, S::Ported, 'community_config.yml description (FormType textarea)'),
                new ScreenElement('register policy choice', L::Two, S::Partial, 'community_config.yml register_policy (FormType radio)', 'rendered as a select, so the OpenPNE 3 radio group and its input_radio class are gone'),
                new ScreenElement('topic read authority (public_flag)', L::Two, S::Ported, 'opCommunityTopicPlugin community_config.yml public_flag', 'radio pair with the OpenPNE 3 choice captions, shared with the community home display'),
                new ScreenElement('topic create authority (topic_authority)', L::Two, S::Ported, 'opCommunityTopicPlugin community_config.yml topic_authority', 'radio pair with the OpenPNE 3 choice captions, shared with the community home display'),
                new ScreenElement('join-notification mail choice', L::Two, S::Partial, 'CommunityConfigForm is_send_pc_joinCommunity_mail (Receive / Don\'t Receive + help line)', 'a single checkbox instead of the two-option radio, with the help line folded into the label'),
                new ScreenElement('photo upload + remove', L::Two, S::Ported, 'CommunityFileForm file (sfWidgetFormInputFileEditable, with_delete)'),
                new ScreenElement('delete-community box', L::Two, S::Ported, "op_include_parts('buttonBox', 'deleteForm')", 'GET form to the delete confirm page, administrator only (a sub-admin may edit but not delete)'),
                new ScreenElement('required-field markers', L::Three, S::Missing, "_partsForm.php mark_required_field + '%0% is required field.'", 'no per-label * marker and no notice line; the inputs carry the HTML required attribute instead'),
            ],
            // joinInput.php / joinError.php → community/join.blade.php
            'join' => [
                new ScreenElement('join confirmation form', L::One, S::Ported, "op_include_form('communityJoining', \$form, body + title)", 'OpenPNE 4 words the question per register policy and adds a Cancel link'),
                new ScreenElement('community preview rows (photo 76×76 + name link)', L::Two, S::Ported, 'joinInput.php firstRow slot'),
                new ScreenElement('already-joined error page', L::Three, S::Missing, 'joinError.php', 'OpenPNE 4 redirects a member or pending applicant back to the community home instead of rendering the error box'),
            ],
            // quitSuccess.php / quitError.php → community/quit.blade.php
            'quit' => [
                new ScreenElement('leave confirmation form', L::One, S::Ported, "op_include_form('communityQuiting', \$form, body + title)", 'OpenPNE 4 adds a Cancel link'),
                new ScreenElement('community preview rows (photo 76×76 + name link)', L::Two, S::Ported, 'quitSuccess.php firstRow slot'),
                new ScreenElement('administrator error page', L::Three, S::Missing, 'quitError.php', 'OpenPNE 4 redirects the administrator, who must hand the community over first, back to the community home'),
            ],
            // deleteSuccess.php → community/delete.blade.php
            'delete' => [
                new ScreenElement('delete confirmation (yesNo)', L::One, S::Ported, "op_include_parts('yesNo', 'deleteConfirmForm')", 'OpenPNE 4 adds the .block statement OpenPNE 3 left to the box title'),
                new ScreenElement('negative answer as a second form', L::Three, S::Partial, '_partsYesNo.php no_form / no_url', 'a Cancel link back to the community, the shape every OpenPNE 4 confirm uses'),
            ],
            // memberManageSuccess.php → community/manage.blade.php, plus the OpenPNE 4-native
            // approval queue that borrows this body id (community/pending.blade.php)
            'memberManage' => [
                new ScreenElement('hand-written parts box + div.item roster table', L::Two, S::Ported, 'memberManageSuccess.php <div class="parts"> … <div class="item"><table>', 'no kind and no id in OpenPNE 3; the id here is OpenPNE 4\'s own'),
                new ScreenElement('member link per row', L::Two, S::Ported, 'memberManageSuccess.php td.member op_link_to_member'),
                new ScreenElement('drop-member link', L::One, S::Ported, "td.drop link_to('community/dropMember')", 'plain-member rows only, so admin / sub-admin rows and the viewer are never droppable'),
                new ScreenElement('sub-administrator appoint / demote links', L::One, S::Partial, "td.sub_admin_request link_to('community/subAdminRequest' or 'community/removeSubAdmin')", "appointment takes effect on confirm, so OpenPNE 3's \"requesting … now\" holding state has no counterpart"),
                new ScreenElement('admin take-over link + pending status', L::One, S::Ported, "td.admin_request link_to('community/changeAdminRequest')"),
                new ScreenElement('pager navigation (above and below)', L::Two, S::Ported, 'memberManageSuccess.php pager slot rendered twice'),
                new ScreenElement('join-request approval queue', L::Two, S::Partial, 'community/_cautionAboutCommunityMemberPre.php → confirmation_list?category=community_confirm', 'an OpenPNE 4-native per-community queue (community.members.pending) borrowing this body id, with the manageList roster and approve / decline buttons; OpenPNE 3 approved joins from the global confirmation centre, which is not ported'),
                new ScreenElement('sub-admin / admin-transfer request notices', L::Two, S::Partial, '_cautionAboutSubAdminRequest.php + _cautionAboutChangeAdminRequest.php p.caution', 'the nominee decides from a banner on the community home plus a feed notification; there is no confirmation centre to link to'),
            ],
            // subAdminRequestInput.php → community/member-action.blade.php (form kind)
            'subAdminRequest' => [
                new ScreenElement('appointment request form', L::One, S::Ported, "op_include_form('communitySubAdminRequest', \$form, title)", 'OpenPNE 4 adds the question paragraph OpenPNE 3 left to the title, plus a Cancel link'),
                new ScreenElement('nominee preview rows (photo 76×76 + nickname link)', L::Two, S::Missing, 'subAdminRequestInput.php firstRow slot', 'the shared confirm blade renders no preview table; the join / leave confirms have theirs'),
            ],
            // removeSubAdminInput.php → community/member-action.blade.php (yesNo kind)
            'removeSubAdmin' => [
                new ScreenElement('demote confirmation (yesNo)', L::One, S::Ported, "op_include_parts('yesNo', 'removeSubAdminConfirmForm', body)"),
                new ScreenElement('negative answer as a second form', L::Three, S::Partial, '_partsYesNo.php no_url=@community_memberManage, no_method=get', 'a Cancel link back to the roster, the shape every OpenPNE 4 confirm uses'),
            ],
            // dropMemberInput.php → community/member-action.blade.php (yesNo kind)
            'dropMember' => [
                new ScreenElement('drop confirmation (yesNo)', L::One, S::Ported, "op_include_parts('yesNo', 'dropMemberConfirmForm', body)"),
                new ScreenElement('negative answer as a second form', L::Three, S::Partial, '_partsYesNo.php no_url=@community_memberManage, no_method=get', 'a Cancel link back to the roster, the shape every OpenPNE 4 confirm uses'),
            ],
            // changeAdminRequestInput.php → community/member-action.blade.php (form kind)
            'changeAdminRequest' => [
                new ScreenElement('take-over request form', L::One, S::Ported, "op_include_form('communityAdminRequest', \$form, title)", 'OpenPNE 4 adds the question paragraph OpenPNE 3 left to the title, plus a Cancel link'),
                new ScreenElement('nominee preview rows (photo 76×76 + nickname link)', L::Two, S::Missing, 'changeAdminRequestInput.php firstRow slot', 'the shared confirm blade renders no preview table; the join / leave confirms have theirs'),
            ],
        ];
    }
}
