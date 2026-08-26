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
        <p data-timeline-compose-fallback><a href="{{ route('timeline.new') }}">{{ __('%Post_activity%') }}</a></p>

        {{-- OpenPNE 3's div.timeline shell: the compose box leads it, then div#timeline-list;
             the load-more button becomes a server pager, the Classic list idiom. --}}
        <div class="timeline">
            @include('timeline._compose', ['returnTo' => 'index'])
            <div id="timeline-list">
                @foreach ($posts as $post)
                    @include('timeline._post', ['post' => $post])
                @endforeach
            </div>
        </div>
        @if ($posts->isEmpty())
            <p>{{ __('No %activity% posts to show.') }}</p>
        @else
            <x-classic.pager :paginator="$posts" />
        @endif
    </x-classic.parts>
@endsection
