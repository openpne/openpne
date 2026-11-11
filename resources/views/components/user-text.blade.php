@props(['value' => null, 'format' => \App\Support\BodyFormat::Plain])
{{-- BodyRenderer::render returns already-escaped, safe HTML (plain: links + line breaks; op3: decoration spans; markdown: sanitized prose); raw output is intentional. --}}
@if ($format === \App\Support\BodyFormat::Markdown)
    <div class="markdownBody">{!! \App\Support\BodyRenderer::render($value, $format) !!}</div>
@else
    {!! \App\Support\BodyRenderer::render($value, $format) !!}
@endif
