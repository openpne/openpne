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
        // coords are the sprite's three glyph runs, measured off the vendored 92×32 bytes.
        $ncIcons = [
            [
                'id' => 'nc_icon1',
                'coords' => '2,0,28,32',
                'href' => route('message.index'),
                'alt' => __('Messages'),
                'count' => $ncCounts['unreadMessages'],
                'label' => __(':count unread messages', ['count' => $ncCounts['unreadMessages']]),
            ],
            [
                'id' => 'nc_icon2',
                'coords' => '36,0,60,32',
                'href' => route('friend.manage'),
                'alt' => __('Pending %friend% requests'),
                'count' => $ncCounts['friendRequests'],
                'label' => __(':count pending %friend% requests', ['count' => $ncCounts['friendRequests']]),
            ],
            [
                'id' => 'nc_icon3',
                'coords' => '66,0,89,32',
                'href' => route('notifications.index'),
                'alt' => __('Notifications'),
                'count' => $ncCounts['notifications'],
                'label' => __(':count unread notifications', ['count' => $ncCounts['notifications']]),
            ],
        ];
    }
@endphp
@if ($ncIcons)
    <div id="notificationCenter">
        <img class="ncbutton" src="{{ asset('images/NOTIFY_CENTER.png') }}" width="92" height="32" alt="" usemap="#notificationCenterMap">
        <map name="notificationCenterMap" id="notificationCenterMap">
            @foreach ($ncIcons as $ncIcon)
                <area shape="rect" coords="{{ $ncIcon['coords'] }}" href="{{ $ncIcon['href'] }}" alt="{{ $ncIcon['alt'] }}">
            @endforeach
        </map>
        {{-- A badge is absent at zero, as OpenPNE 3's was. The skin sizes it for OpenPNE 3's
             capped count, so the digits stop at 99+ and the real number stays in the name. --}}
        @foreach ($ncIcons as $ncIcon)
            @if ($ncIcon['count'] > 0)
                <span id="{{ $ncIcon['id'] }}" role="img" aria-label="{{ $ncIcon['label'] }}" title="{{ $ncIcon['label'] }}">{{ $ncIcon['count'] > 99 ? '99+' : $ncIcon['count'] }}</span>
            @endif
        @endforeach
    </div>
@endif
