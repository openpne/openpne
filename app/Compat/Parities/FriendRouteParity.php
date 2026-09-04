<?php

namespace App\Compat\Parities;

use App\Compat\CompatLevel as L;
use App\Compat\RouteMap;
use App\Compat\RouteParity;
use App\Compat\ScreenElement;
use App\Compat\ScreenStatus as S;

class FriendRouteParity extends RouteParity
{
    protected string $module = 'friend';

    public function maps(): array
    {
        return [
            new RouteMap('friend_list', '/friend/list', 'friend.list', 'GET', op3Action: 'list'),
            new RouteMap('friend_manage', '/friend/manage', 'friend.manage', 'GET', op3Action: 'manage'),
            // The pending-request queues are OpenPNE 4-native: OpenPNE 3 had no such page — its
            // requests were answered from the notification center and the home cautions.
            new RouteMap(null, null, 'friend.requests', 'GET', op3Action: 'requests'),
            // link is fallback-reached in OpenPNE 3 (no named route), one action serving the form on
            // GET and the submit on POST.
            new RouteMap(null, null, 'friend.link.show', 'GET', op3Action: 'link'),
            new RouteMap(null, null, 'friend.link', 'POST'),
            // One OpenPNE 3 route (sf_method get,post) splits into a GET confirm + POST submit.
            new RouteMap('obj_friend_unlink', '/friend/unlink/:id', 'friend.unlink.show', 'GET', op3Action: 'unlink'),
            new RouteMap('obj_friend_unlink', '/friend/unlink/:id', 'friend.unlink.submit', 'POST'),
        ];
    }

    public function screens(): array
    {
        return [
            // listSuccess.php: a roster as one photoTable parts; listError.php when it is empty.
            'list' => [
                new ScreenElement('photoTable band, id friendList', L::One, S::Ported, "listSuccess.php op_include_parts('photoTable', 'friendList')"),
                new ScreenElement('band title', L::Three, S::Partial, "listSuccess.php title '%friend% List'", "OpenPNE 4 prints '%Friends%' on your own roster and \":name's %friends%\" on another member's, where OpenPNE 3 printed one title for both"),
                new ScreenElement('5-column bands: 76×76 photo row then name row, short rows padded with empty td', L::One, S::Ported, '_partsPhotoTable.php col=5, tr.photo / tr.text', 'x-classic.photo-table'),
                new ScreenElement('both cells link to the member profile', L::One, S::Ported, "_partsPhotoTable.php op_link_to_member(..., '@obj_member_profile') under use_op_link_to_member"),
                new ScreenElement('name shown as "name (%friend% count)"', L::Two, S::Ported, "Member::getNameAndCount('%s (%d)')", "withCount('friendships'); OpenPNE 3 drops the count when enable_friend_link is off, where OpenPNE 4 removes the screen with the %friend% unit"),
                new ScreenElement('pager above and below the table', L::Two, S::Ported, '_partsPhotoTable.php op_include_pager_navigation ×2 (rendered once, echoed twice)'),
                new ScreenElement('50 members per page', L::Two, S::Partial, 'friendActions::executeList $this->size = 50', 'OpenPNE 4 pages at 20 (ListFriends default), so the same roster spans more pages'),
                new ScreenElement("?id= subject: another member's roster, kept on the pager links", L::Two, S::Ported, "opFriendAction::preExecute \$this->id = getRequestParameter('id', myMemberId) + link_to_pager '@friend_list?page=%d&id='", 'withQueryString keeps ?id= on page 2'),
                new ScreenElement('empty roster swaps in the noFriend box + backLink line', L::Two, S::Ported, "listError.php op_include_box('noFriend', \"You don't have any %friend%.\") + backLink link_to_function history.back()", "x-classic.history-back with a home fallback; OpenPNE 3's line stays second-person on another member's roster"),
                new ScreenElement('a member who blocks the viewer hides their roster', L::Two, S::Ported, "executeList redirectIf(\$this->relation->isAccessBlocked(), '@error')", 'MemberPolicy::access answers 404 rather than the OpenPNE 3 error page, and ListFriends empties the query'),
                new ScreenElement("friend localNav on another member's roster, default on your own", L::Two, S::Ported, "friend/config/module.yml default_nav: friend + friendActions::preExecute sf_nav_type='default' when the id is yours", 'markLocalNavSubject records only a non-self subject'),
                new ScreenElement('members only: a guest is sent to login', L::Two, S::Ported, 'friend/config/security.yml is_secure + credentials SNSMember', 'auth middleware plus the %friend% feature gate'),
            ],
            // manageSuccess.php: the member's own roster as one manageList parts.
            'manage' => [
                new ScreenElement('roster manageList (76×76 photo + name, one row per %friend%)', L::One, S::Ported, "op_include_parts('manageList','manageList') over getFriendListPager"),
                new ScreenElement('unlink column', L::One, S::Ported, "menus: 'Delete from %my_friend%.' → obj_friend_unlink, class delete"),
                new ScreenElement('pager above and below', L::Two, S::Ported, 'op_include_pager_navigation ×2 in _partsManageList.php'),
                new ScreenElement('empty state box + history-back line', L::Two, S::Ported, "manageError.php op_include_box('manageFriendWarning') + backLink link_to_function history.back()", 'x-classic.history-back: href fallback to friend/list, history.back() when the script attaches'),
            ],
            // linkInput.php: the friend-request form, a form parts with the target member as its first rows.
            'link' => [
                new ScreenElement('form box id friendLink (form kind)', L::Two, S::Ported, "op_include_form('friendLink', \$form, title 'Add %my_friend%')"),
                new ScreenElement("box title 'Add %my_friend%'", L::Three, S::Partial, "linkInput.php 'Add %my_friend%'", "headed 'Send a %friend% request'"),
                new ScreenElement('target member rows (76×76 photo + %nickname%, linking to the profile)', L::Two, S::Missing, "linkInput.php firstRow: Photo row op_image_tag_sf_image 76x76 + %nickname% row link_to('@member_profile')", 'the question sentence sits in a div.block instead; no table rows and no photo'),
                new ScreenElement('submit-only form (FriendLinkForm has no fields)', L::One, S::Ported, 'FriendLinkForm::configure() name format friend_link[%s] + submit', 'POST friend.link with the target id in a hidden input; a Cancel link back to the roster is an OpenPNE 4 addition'),
            ],
            // unlinkInput.php: the yesNo confirm.
            'unlink' => [
                new ScreenElement('yesNo confirm with the member linked in the heading', L::One, S::Ported, "op_include_parts('yesNo','unlinkConfirmForm')"),
                new ScreenElement('not-a-%friend% answered with a notice on manage, never a 404', L::Two, S::Ported, "executeUnlink flash 'This member is not your %friend%.' + redirect friend/manage", 'covers a vanished member too; self / empty id go home as redirectToHomeIfIdIsNotValid did'),
                new ScreenElement('cancel and completion return to manage', L::Two, S::Ported, "no_url '@friend_manage' / removeFriend + redirect friend/manage", 'Classic only — Modern rosters live on friend/list and return there'),
            ],
        ];
    }

    public function gaps(): array
    {
        return [
            'friend_show_image' => 'OpenPNE 3 friend/showImage is the member photo page; the image bytes are served by FileController, but the photo page itself is not ported.',
        ];
    }

    public function acknowledgesGlobalFallback(): bool
    {
        return true;
    }
}
