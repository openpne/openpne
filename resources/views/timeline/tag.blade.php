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
            @if ($posts->hasMorePages())
                @include('timeline._loadmore', ['nextUrl' => route('timeline.tag.rows', ['tag' => $tag, 'page' => $posts->currentPage() + 1])])
            @endif
        </div>
        @if ($posts->isEmpty())
            <p>{{ __('No %activity% posts to show.') }}</p>
        @else
            <div data-timeline-pager><x-classic.pager :paginator="$posts" /></div>
        @endif
    </x-classic.parts>
@endsection
