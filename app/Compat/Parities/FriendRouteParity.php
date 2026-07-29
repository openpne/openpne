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
            // OpenPNE 3 reaches link through the global /:module/:action fallback (no named
            // route); executeLink serves the form on GET and the request submit on POST.
            // OpenPNE 4 splits them into explicit routes.
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
            // manageSuccess.php: the member's own roster as one manageList parts.
            'manage' => [
                new ScreenElement('roster manageList (76×76 photo + name, one row per %friend%)', L::One, S::Ported, "op_include_parts('manageList','manageList') over getFriendListPager"),
                new ScreenElement('unlink column', L::One, S::Ported, "menus: 'Delete from %my_friend%.' → obj_friend_unlink, class delete"),
                new ScreenElement('pager above and below', L::Two, S::Ported, 'op_include_pager_navigation ×2 in _partsManageList.php'),
                new ScreenElement('empty state box + history-back line', L::Two, S::Ported, "manageError.php op_include_box('manageFriendWarning') + backLink link_to_function history.back()", 'x-classic.history-back: href fallback to friend/list, history.back() when the script attaches'),
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
