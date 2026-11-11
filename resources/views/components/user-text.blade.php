@props(['value' => null, 'format' => \App\Support\BodyFormat::Plain])
{{-- BodyRenderer::render returns already-escaped, safe HTML (plain: links + line breaks; op3: decoration spans; markdown: sanitized prose); raw output is intentional. --}}
@if ($format === \App\Support\BodyFormat::Markdown)
    {{-- The OpenPNE 3 skin (opSkinBasicPlugin) is a hard reset — it zeroes strong/em, list markers,
         headings and blockquotes — so Markdown prose renders flat without these rules. Scoped to
         .markdownBody (added only for a Markdown body) and emitted once per page. --}}
    @include('partials.markdown-body-style')
    <div class="markdownBody">{!! \App\Support\BodyRenderer::render($value, $format) !!}</div>
@else
    {!! \App\Support\BodyRenderer::render($value, $format) !!}
@endif
