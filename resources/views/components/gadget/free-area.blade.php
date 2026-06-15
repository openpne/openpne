{{-- OpenPNE 3 default/freeAreaBox (box parts): an optional title + admin-authored HTML body. The
     body is trusted operator HTML (like the Classic footer), so it is rendered unescaped. --}}
@props(['config' => [], 'subject' => null, 'partId' => null])
<x-gadget-part :part-id="$partId" part-name="box" :title="($config['title'] ?? '') !== '' ? $config['title'] : null">
    <div class="body">{!! $config['value'] ?? '' !!}</div>
</x-gadget-part>
