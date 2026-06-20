{{-- OpenPNE 3 #sideBanner: a global right-column gadget area on every Classic PC page (guests
     included). Resolved here in the shell, not per page; a guest sees only viewable-by-anyone kinds.
     Rendered only when populated, so the skin's reserved 225px column is not an empty float. --}}
@php($sideBannerZones = app(\App\Services\GadgetService::class)->zones('sidebanner', viewer: auth()->user()))
@if (collect($sideBannerZones)->flatten(1)->isNotEmpty())
    <div id="sideBanner">
        @foreach ($sideBannerZones as $items)
            <x-gadget-zone :items="$items" />
        @endforeach
    </div><!-- sideBanner -->
@endif
