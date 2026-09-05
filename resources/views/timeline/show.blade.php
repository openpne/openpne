@extends('layouts.classic')

@php($title = __(":name's %activity%", ['name' => $post->member->name]))

@section('title', $title)

@section('content')
    @include('timeline._stylesheets')
    @include('timeline._scripts')
    {{-- OpenPNE 3's showSuccess.php emits a bare partsHeading over a .timeline-large div its themes
         alias to the box treatment, so there is no kind to reproduce; the frame keeps the heading
         inside the box and the OpenPNE 4 id stands. --}}
    <x-classic.parts id="timeline_show" :title="$title">
        @if (session('status'))
            <p role="status">{{ session('status') }}</p>
        @endif

        {{-- OpenPNE 3 showSuccess.php's div.timeline-large > div#timeline-list shell around the
             thread root, which renders as the shared row; its comment link is a same-page jump
             to the reply form below. The row carries the whole thread here, oldest first
             (OpenPNE 3 reads by id), and no inline form — this page's own is that. --}}
        <div class="timeline-large">
            <div id="timeline-list">
                @include('timeline._post', ['post' => $post, 'thread' => true])
            </div>
        </div>

        @if (\App\Features\Timeline\TimelinePosting::enabled())
            <form method="POST" action="{{ route('timeline.reply.store', $post) }}" id="timeline-reply-form" class="timeline-reply-form"
                  data-timeline-mention data-mention-candidates-url="{{ route('timeline.mention_candidates') }}" data-mention-no-image-url="{{ asset('images/no_image.gif') }}" data-mention-label="{{ __('Mention candidates') }}">
                @csrf
                @include('timeline._mention-draft')
                <textarea name="body" required aria-label="{{ __('Post comment') }}">{{ old('body') }}</textarea>
                @error('body')
                    <p role="alert">{{ $message }}</p>
                @enderror
                <button type="submit">{{ __('Reply') }}</button>
            </form>
            @include('timeline._mention-picker')
        @endif

        <p><a href="{{ route('timeline.member', $post->member) }}">{{ __(":name's %activity%", ['name' => $post->member->name]) }}</a></p>
    </x-classic.parts>
@endsection
