{{-- OpenPNE 3 `_pagerNavigation.php` without `_pagerTotal.php`: a stream has no count to read out, and
     "previous" is always the head (docs/internals/ordering.md, "Keyset and offset"). Renders nothing when
     there is nowhere to go, so callers keep their empty-state swap. --}}
@props(['olderUrl' => null, 'newerUrl' => null])
@if ($olderUrl !== null || $newerUrl !== null)
    <div class="pagerRelative">
        @if ($newerUrl !== null)
            <p class="prev"><a href="{{ $newerUrl }}">{{ __('Show previous') }}</a></p>
        @endif
        @if ($olderUrl !== null)
            <p class="next"><a href="{{ $olderUrl }}">{{ __('Show next') }}</a></p>
        @endif
    </div>
@endif
