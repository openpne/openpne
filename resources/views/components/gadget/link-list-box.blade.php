{{-- OpenPNE 3 default/linkListBox (box parts): a title + up to ten text/url link pairs. --}}
@props(['config' => [], 'subject' => null, 'partId' => null])
<x-gadget-part :part-id="$partId" part-name="box" :title="($config['title'] ?? '') !== '' ? $config['title'] : __('Links')">
    <div class="body">
        <ul>
            @for ($i = 1; $i <= 10; $i++)
                @php($url = $config['url'.$i] ?? '')
                @php($text = $config['text'.$i] ?? '')
                @if ($url !== '' && $text !== '')
                    <li><a href="{{ $url }}">{{ $text }}</a></li>
                @endif
            @endfor
        </ul>
    </div>
</x-gadget-part>
