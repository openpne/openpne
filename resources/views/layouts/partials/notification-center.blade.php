{{-- OpenPNE 3's `#notificationCenter` (`_header.php`), secure pages only: one sprite of three
     icons — envelope, members, at-sign — each carrying the number waiting behind it.

     The sprite is one control, not three. OpenPNE 3 bound a single click to `.ncbutton` and opened
     this panel in place; the icons never navigated and the badges were never targets. Splitting it
     into three links reads right and behaves wrong, which for a surface whose only audience already
     knows the original is worse than an unfamiliar control — so the panel is what we restore, and
     `.ncbutton` goes back to being what it is in OpenPNE 3: the click hook (no stylesheet, here or
     there, ever matched it).

     Without JavaScript the trigger stays an ordinary link to the feed, so the control is never
     dead; the script cancels that and opens the panel instead. Rows arrive on first open, as
     OpenPNE 3's did, so a page whose panel is never opened pays nothing for it.

     Resolved from the member guard rather than the default one: the Classic shell also renders
     for a guest (a web-public profile) and for an error page, where there is nobody to count for. --}}
@php
    $ncViewer = request()->user('member');
    $ncCounts = $ncViewer instanceof \App\Models\Member
        ? app(\App\Features\Notifications\NotificationCenterCounts::class)->for($ncViewer)
        : null;
@endphp
@if ($ncCounts !== null)
    @once
        {{-- OpenPNE 3's rows were plain divs it made clickable with script; ours submit, so the
             button has to stop looking like one. Class selectors, so a site can outrank these with
             an ordinary more-specific rule. --}}
        <style>
            .ncbuttonLink { text-decoration: none; }
            .notificationCenterRowLink { padding: 0; border: 0; background: none; font: inherit; color: inherit; text-align: left; cursor: pointer; }
        </style>
    @endonce
    <div id="notificationCenter">
        <a href="{{ route('notifications.index') }}" class="ncbuttonLink" aria-label="{{ __('Notification Center') }}"
           aria-expanded="false" aria-controls="notificationCenterDetail"
           data-notification-center-url="{{ route('notifications.center') }}">
            <img class="ncbutton" src="{{ asset('images/NOTIFY_CENTER.png') }}" width="92" height="32" alt="">
        </a>
        <div id="notificationCenterDetail">
            <div id="notificationCenterDetailHeader">{{ __('Notification Center') }}</div>
            <div id="notificationCenterLoading"><img src="{{ asset('images/ajax-loader.gif') }}" alt=""></div>
            <div id="notificationCenterError">{{ __('There is no new notification.') }}</div>
        </div>
        {{-- A badge is absent at zero, as OpenPNE 3's was, and shows what its own compartment of
             the panel holds. It needs no clamp: the window caps the whole centre at 20, which is
             what the skin sizes these for. --}}
        @foreach (\App\Features\Notifications\NotificationCenterCategory::cases() as $ncCategory)
            @php($ncCount = $ncCounts[$ncCategory->value] ?? 0)
            @if ($ncCount > 0)
                <span id="{{ $ncCategory->badgeId() }}">{{ $ncCount }}</span>
            @endif
        @endforeach
    </div>
    @once
        <script src="{{ asset('js/classic-notification-center.js') }}" defer></script>
    @endonce
@endif
