@extends('layouts.classic')

@php($title = __('%Activity%'))

@section('title', $title)

@section('content')
    @include('timeline._stylesheets')
    @include('timeline._scripts')
    {{-- OpenPNE 3 streams posts client-side from the API; the Classic adapter renders them
         server-side with a pager. It served this feed only as the homeAllTimeline gadget, whose id
         carries a gadget suffix; the standalone page keeps the bare kind name as its id. --}}
    @php($canPost = \App\Features\Timeline\TimelinePosting::enabled())
    <x-classic.parts id="homeAllTimeline" name="homeAllTimeline" :title="$title">
        {{-- The no-JS compose path; classic-timeline-compose.js swaps it for the inline form. --}}
        @if ($canPost)
            <p data-timeline-compose-fallback><a href="{{ route('timeline.new') }}">{{ __('%Post_activity%') }}</a></p>
        @endif

        {{-- OpenPNE 3's div.timeline shell: the compose box leads it, then div#timeline-list and
             the load-more control; the server pager beside it is the way on without the script. --}}
        <div class="timeline" data-timeline-container>
            @include('timeline._compose', ['returnTo' => 'index', 'canPost' => $canPost])
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
