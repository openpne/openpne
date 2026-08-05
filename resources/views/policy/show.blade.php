@extends('layouts.classic')

@section('title', $title)

@section('content')
    {{-- OpenPNE 3 op_include_box($boxId, nl2br(...)): the box carries the policy's own id (a site
         CSS hook) and no heading. The heading is an OpenPNE 4 addition — the page had no h1 at all. --}}
    <x-classic.parts :id="$boxId" name="box" :title="$title">
        <div class="body">
            @if ($body === '')
                <p>{{ __('This page is not written yet.') }}</p>
            @else
                <x-user-text :value="$body" :format="\App\Support\BodyFormat::Markdown" />
            @endif
        </div>
    </x-classic.parts>
@endsection
