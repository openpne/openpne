{{-- The side banner, emitted bare (OpenPNE 3 op_banner): no wrapper — the #sideBanner column is the gadget zone. --}}
@props(['config' => [], 'subject' => null, 'partId' => null, 'context' => null])
{!! classic_banner(auth()->check() ? 'side_after' : 'side_before') !!}
