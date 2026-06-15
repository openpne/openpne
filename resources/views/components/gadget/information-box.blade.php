{{-- OpenPNE 3 default/informationBox: admin-authored announcement HTML (trusted, unescaped). --}}
@props(['config' => [], 'subject' => null, 'partId' => null])
<x-gadget-part :part-id="$partId">
    {!! $config['value'] ?? '' !!}
</x-gadget-part>
