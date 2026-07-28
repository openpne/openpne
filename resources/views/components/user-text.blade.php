@props(['value' => null, 'format' => \App\Support\BodyFormat::Plain])
{{-- BodyRenderer::render returns already-escaped, safe HTML (plain: links + line breaks; op3: decoration spans; markdown: sanitized prose); raw output is intentional. --}}
@if ($format === \App\Support\BodyFormat::Markdown)
    {{-- The OpenPNE 3 skin (opSkinBasicPlugin) is a hard reset — it zeroes strong/em, list markers,
         headings and blockquotes — so Markdown prose renders flat without these rules. Scoped to
         .markdownBody (added only for a Markdown body) and emitted once per page via @once. --}}
    @once
        <style>
            /* A detail box rules off every div inside its dd (.diaryDetailBox dd div); this
               wrapper is an OpenPNE 4 addition within one, not a section of its own. */
            {{-- Outranks diary.css's `.diaryDetailBox dd div` (0-1-2): a bare .markdownBody
                 (0-1-0) loses on specificity regardless of source order. --}}
            .diaryDetailBox dd div.markdownBody { border-top: none; }
            .markdownBody p { margin: 0 0 0.75em; }
            .markdownBody strong { font-weight: bold; }
            .markdownBody em { font-style: italic; }
            .markdownBody del { text-decoration: line-through; }
            .markdownBody ul,
            .markdownBody ol { margin: 0 0 0.75em; padding-left: 1.5em; }
            .markdownBody ul { list-style: disc outside; }
            .markdownBody ol { list-style: decimal outside; }
            .markdownBody li { margin: 0.15em 0; }
            .markdownBody blockquote { margin: 0 0 0.75em; padding: 0.1em 0 0.1em 0.8em; border-left: 3px solid #CCCCCC; color: #666666; }
            .markdownBody code { padding: 0.1em 0.35em; background: #F2F2F2; border-radius: 3px; font-family: monospace; }
            .markdownBody pre { margin: 0 0 0.75em; padding: 0.7em 0.9em; background: #F2F2F2; border-radius: 4px; overflow-x: auto; }
            .markdownBody pre code { padding: 0; background: none; }
            .markdownBody h1 { font-size: 1.5em; font-weight: bold; margin: 0.6em 0 0.4em; }
            .markdownBody h2 { font-size: 1.3em; font-weight: bold; margin: 0.6em 0 0.4em; }
            .markdownBody h3 { font-size: 1.15em; font-weight: bold; margin: 0.6em 0 0.4em; }
            .markdownBody h4,
            .markdownBody h5,
            .markdownBody h6 { font-size: 1em; font-weight: bold; margin: 0.6em 0 0.4em; }
            .markdownBody table { border-collapse: collapse; margin: 0 0 0.75em; }
            .markdownBody th,
            .markdownBody td { border: 1px solid #CCCCCC; padding: 0.3em 0.6em; }
            .markdownBody th { font-weight: bold; background: #F7F7F7; }
        </style>
    @endonce
    <div class="markdownBody">{!! \App\Support\BodyRenderer::render($value, $format) !!}</div>
@else
    {!! \App\Support\BodyRenderer::render($value, $format) !!}
@endif
