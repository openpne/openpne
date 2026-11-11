@props(['config' => [], 'subject' => null, 'partId' => null])
<x-classic.parts :id="$partId" name="informationBox">
    <div class="body sortHandle">{!! \App\Support\OperatorContent::render($config['value'] ?? null, $config['value_markdown'] ?? null) !!}</div>
</x-classic.parts>
