{{-- OpenPNE 3's `#notificationCenter` (`_header.php`), secure pages only: one sprite of three
     icons — envelope, members, at-sign — each with the count that is waiting behind it.

     The sprite stays a single `img.ncbutton`, the hook OpenPNE 3's skins and customer CSS style
     it through, and the three icons become links through an image map. Cutting it into three
     background slices would drop that hook, and the rules replacing it would sit in the document
     after the admin's custom stylesheet, out of its reach.

     Resolved from the member guard rather than the default one: the Classic shell also renders
     for a guest (a web-public profile) and for an error page, where there is nobody to count for. --}}
@php
    $ncViewer = request()->user('member');
    $ncIcons = [];

    if ($ncViewer instanceof \App\Models\Member) {
        $ncCounts = app(\App\Features\Home\UnreadCounts::class)->for($ncViewer);
        // The sprite's three cells, not the glyph runs inside them: the ink measures 26/24/23px
        // wide, which would leave the gaps between icons dead and the at-sign barely tappable.
        $ncIcons = [
            [
                'id' => 'nc_icon1',
                'coords' => '0,0,30,32',
                'href' => route('message.index'),
                'count' => $ncCounts['unreadMessages'],
                'name' => $ncCounts['unreadMessages'] > 0
                    ? __(':count unread messages', ['count' => $ncCounts['unreadMessages']])
                    : __('Messages'),
            ],
            [
                'id' => 'nc_icon2',
                'coords' => '31,0,61,32',
                'href' => route('friend.manage'),
                'count' => $ncCounts['friendRequests'],
                'name' => $ncCounts['friendRequests'] > 0
                    ? __(':count pending %friend% requests', ['count' => $ncCounts['friendRequests']])
                    : __('Pending %friend% requests'),
            ],
            [
                'id' => 'nc_icon3',
                'coords' => '62,0,92,32',
                'href' => route('notifications.index'),
                'count' => $ncCounts['notifications'],
                'name' => $ncCounts['notifications'] > 0
                    ? __(':count unread notifications', ['count' => $ncCounts['notifications']])
                    : __('Notifications'),
            ],
        ];
    }
@endphp
@if ($ncIcons)
    @once
        {{-- The skin colours the badge by id but leaves the underline a link would bring. --}}
        <style>.notificationCenterBadge { text-decoration: none; }</style>
    @endonce
    <div id="notificationCenter">
        <img class="ncbutton" src="{{ asset('images/NOTIFY_CENTER.png') }}" width="92" height="32" alt="" usemap="#notificationCenterMap">
        {{-- The area is the only thing here that is announced or hoverable, so the count rides on
             it: the badge that shows the digits cannot be reached by either. --}}
        <map name="notificationCenterMap" id="notificationCenterMap">
            @foreach ($ncIcons as $ncIcon)
                <area shape="rect" coords="{{ $ncIcon['coords'] }}" href="{{ $ncIcon['href'] }}" alt="{{ $ncIcon['name'] }}" title="{{ $ncIcon['name'] }}">
            @endforeach
        </map>
        {{-- A badge is absent at zero, as OpenPNE 3's was. The skin sizes it for OpenPNE 3's
             capped count, so the digits stop at 99+ and the real number stays on the area.

             It leads where the icon it sits on leads: the skin drops the badge over that icon and
             lets a wide one hang past the sprite, so a badge that were not itself a target would
             both swallow the clicks it covers and waste the ones it overhangs. It repeats the area
             rather than joining it — hidden and out of the tab order — so the count is announced
             once and the keyboard still sees three links. --}}
        @foreach ($ncIcons as $ncIcon)
            @if ($ncIcon['count'] > 0)
                <a href="{{ $ncIcon['href'] }}" id="{{ $ncIcon['id'] }}" class="notificationCenterBadge" aria-hidden="true" tabindex="-1">{{ $ncIcon['count'] > 99 ? '99+' : $ncIcon['count'] }}</a>
            @endif
        @endforeach
    </div>
@endif
