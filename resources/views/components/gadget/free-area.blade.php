{{-- OpenPNE 3 default/freeAreaBox: an optional title + admin-authored HTML body. The body is
     trusted operator HTML (like the Classic footer), so it is rendered unescaped. --}}
@props(['config' => [], 'subject' => null, 'partId' => null])
<x-gadget-part :part-id="$partId" :title="($config['title'] ?? '') !== '' ? $config['title'] : null">
    {!! $config['value'] ?? '' !!}
</x-gadget-part>
