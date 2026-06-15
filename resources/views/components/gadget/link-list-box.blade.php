{{-- OpenPNE 3 default/linkListBox: an optional title + up to ten text/url link pairs. --}}
@props(['config' => [], 'subject' => null, 'partId' => null])
<x-gadget-part :part-id="$partId" :title="($config['title'] ?? '') !== '' ? $config['title'] : null">
    <ul>
        @for ($i = 1; $i <= 10; $i++)
            @php($url = $config['url'.$i] ?? '')
            @php($text = $config['text'.$i] ?? '')
            @if ($url !== '' && $text !== '')
                <li><a href="{{ $url }}">{{ $text }}</a></li>
            @endif
        @endfor
    </ul>
</x-gadget-part>
