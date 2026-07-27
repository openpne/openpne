{{-- Admin-authored announcement HTML (trusted, unescaped). --}}
@props(['config' => [], 'subject' => null, 'partId' => null])
<x-classic.parts :id="$partId" name="informationBox">
    <div class="body sortHandle">{!! $config['value'] ?? '' !!}</div>
</x-classic.parts>
