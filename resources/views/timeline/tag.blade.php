@extends('layouts.classic')

@php($title = __('%Activity% posts tagged #:tag', ['tag' => $tag]))

@section('title', $title)

@section('content')
    @php($canPost = \App\Features\Timeline\TimelinePosting::enabled())
    @include('timeline._stylesheets')
    @include('timeline._scripts')
    {{-- A reading page: the same OpenPNE 3 timeline shell as the home feed, without its compose box
         — nothing here says which tag a new post would carry. --}}
    <x-classic.parts id="homeAllTimeline" name="homeAllTimeline" :title="$title">
        <div class="timeline" data-timeline-container>
            <div id="timeline-list">
                @foreach ($posts as $post)
                    @include('timeline._post', ['post' => $post, 'canPost' => $canPost])
                @endforeach
            </div>
            @if ($loadMoreUrl !== null)
                @include('timeline._loadmore', ['nextUrl' => $loadMoreUrl])
            @endif
        </div>
        @if ($posts->isEmpty())
            <p>{{ $newerUrl !== null ? __('No older posts.') : __('No %activity% posts to show.') }}</p>
        @endif
        <div data-timeline-pager><x-classic.stream-pager :older-url="$olderUrl" :newer-url="$newerUrl" /></div>
    </x-classic.parts>
@endsection
