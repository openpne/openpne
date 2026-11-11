@props(['config' => [], 'subject' => null, 'partId' => null])
<x-classic.parts :id="$partId" name="box" :title="($config['title'] ?? '') !== '' ? $config['title'] : null">
    <div class="body">{!! \App\Support\OperatorContent::render($config['value'] ?? null, $config['value_markdown'] ?? null) !!}</div>
</x-classic.parts>
