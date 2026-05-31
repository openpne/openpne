<?php

namespace App\Compat\Parities;

use App\Compat\RouteMap;
use App\Compat\RouteParity;

class FriendRouteParity extends RouteParity
{
    protected string $module = 'friend';

    public function maps(): array
    {
        return [
            new RouteMap('friend_list', '/friend/list', 'friend.list', 'GET', op3Action: 'list'),
            new RouteMap('friend_manage', '/friend/manage', 'friend.manage', 'GET', op3Action: 'manage'),
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

    public function gaps(): array
    {
        return [
            'friend_show_image' => 'Avatar image is served via the File substrate, not ported here.',
        ];
    }

    public function acknowledgesGlobalFallback(): bool
    {
        return true;
    }
}
