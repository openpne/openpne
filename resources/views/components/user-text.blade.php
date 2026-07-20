@props(['value' => null, 'format' => \App\Support\BodyFormat::Plain])
{{-- BodyRenderer::render returns already-escaped, safe HTML (plain: links + line breaks; op3: decoration spans); raw output is intentional. --}}
{!! \App\Support\BodyRenderer::render($value, $format) !!}