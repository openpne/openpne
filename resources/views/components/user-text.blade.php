@props(['value' => null])
{{-- BodyText::render returns already-escaped, safe HTML (links + line breaks); raw output is intentional. --}}
{!! \App\Support\BodyText::render($value) !!}