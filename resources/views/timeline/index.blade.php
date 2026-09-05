@extends('layouts.classic')

@php($title = __('%Activity%'))

@section('title', $title)

@section('content')
    @include('timeline._stylesheets')
    @include('timeline._scripts')
    {{-- OpenPNE 3 streams posts client-side from the API; the Classic adapter renders them
         server-side with a pager. It served this feed only as the homeAllTimeline gadget, whose id
         carries a gadget suffix; the standalone page keeps the bare kind name as its id. --}}
    <x-classic.parts id="homeAllTimeline" name="homeAllTimeline" :title="$title">
        {{-- The no-JS compose path; classic-timeline-compose.js swaps it for the inline form. --}}
        @if (\App\Features\Timeline\TimelinePosting::enabled())
            <p data-timeline-compose-fallback><a href="{{ route('timeline.new') }}">{{ __('%Post_activity%') }}</a></p>
        @endif

        {{-- OpenPNE 3's div.timeline shell: the compose box leads it, then div#timeline-list and
             the load-more control; the server pager beside it is the way on without the script. --}}
        <div class="timeline" data-timeline-container>
            @include('timeline._compose', ['returnTo' => 'index'])
            <div id="timeline-list">
                @foreach ($posts as $post)
                    @include('timeline._post', ['post' => $post])
                @endforeach
            </div>
            @if ($posts->hasMorePages())
                @include('timeline._loadmore', ['nextUrl' => route('timeline.index.rows', ['page' => $posts->currentPage() + 1])])
            @endif
        </div>
        @if ($posts->isEmpty())
            <p>{{ __('No %activity% posts to show.') }}</p>
        @else
            <div data-timeline-pager><x-classic.pager :paginator="$posts" /></div>
        @endif
    </x-classic.parts>
@endsection
