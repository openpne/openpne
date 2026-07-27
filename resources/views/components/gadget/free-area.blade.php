{{-- An optional title + admin-authored HTML body. The body is trusted operator HTML (like the
     Classic footer), so it is rendered unescaped. --}}
@props(['config' => [], 'subject' => null, 'partId' => null])
<x-classic.parts :id="$partId" name="box" :title="($config['title'] ?? '') !== '' ? $config['title'] : null">
    <div class="body">{!! $config['value'] ?? '' !!}</div>
</x-classic.parts>
