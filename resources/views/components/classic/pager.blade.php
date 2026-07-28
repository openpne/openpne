{{-- OpenPNE 3 `_pagerNavigation.php` + `_pagerTotal.php`: prev / count readout / next in one
     div.pagerRelative. The readout is why a single-page list still gets the block — it answers "how
     many are there", not only "where am I". A list with no results renders nothing: OpenPNE 3
     forwarded to an *Error template that dropped the list and its pager together, so callers keep
     the empty-state swap. `paginator` is a LengthAwarePaginator; page name and query string come
     from it, so a caller wanting `?keyword=` kept calls withQueryString() before passing it. --}}
@props(['paginator'])
@if ($paginator->total() > 0)
    <div class="pagerRelative">
        @if ($paginator->previousPageUrl())
            <p class="prev"><a href="{{ $paginator->previousPageUrl() }}">{{ __('Show previous') }}</a></p>
        @endif
        <p class="number">{{ __('Showing :first - :last of :total', ['first' => $paginator->firstItem(), 'last' => $paginator->lastItem(), 'total' => $paginator->total()]) }}</p>
        @if ($paginator->nextPageUrl())
            <p class="next"><a href="{{ $paginator->nextPageUrl() }}">{{ __('Show next') }}</a></p>
        @endif
    </div>
@endif
