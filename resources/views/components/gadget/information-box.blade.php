{{-- OpenPNE 3 default/informationBox (single parts): admin-authored announcement HTML (trusted,
     unescaped). --}}
@props(['config' => [], 'subject' => null, 'partId' => null])
<x-gadget-part :part-id="$partId" part-name="informationBox" :single="true">
    <div class="body sortHandle">{!! $config['value'] ?? '' !!}</div>
</x-gadget-part>
