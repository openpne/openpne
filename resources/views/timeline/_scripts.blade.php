{{-- The Classic timeline's resident scripts, included by the screens and the gadget wrappers — never
     by a row or a fragment. insertAdjacentHTML does not run a script, so one that shipped inside a
     fragment would never start; and the fragment re-renders @once from scratch, so it would ship a
     copy with every insertion. Loaded whatever the first rows hold, because later ones arrive from
     the server. --}}
@once
    {{-- The timeago words, from the same catalog as the rest of the page; the script fills :count. --}}
    @php($timeagoStrings = [
        'minute' => __('A minute ago'), 'minutes' => __(':count minutes ago'),
        'hour' => __('An hour ago'), 'hours' => __(':count hours ago'),
        'day' => __('A day ago'), 'days' => __(':count days ago'),
        'month' => __('A month ago'), 'months' => __(':count months ago'),
        'year' => __('A year ago'), 'years' => __(':count years ago'),
    ])
    <script type="application/json" id="classic-timeago-strings">@json($timeagoStrings, JSON_HEX_TAG | JSON_HEX_AMP)</script>
    @include('timeline._lightbox')
    <script src="{{ asset('js/classic-timeline-replies.js') }}" defer></script>
    <script src="{{ asset('js/classic-timeline-more.js') }}" defer></script>
    <script src="{{ asset('js/classic-timeago.js') }}" defer></script>
    <script src="{{ asset('js/classic-timeline-dialogs.js') }}" defer></script>
@endonce
