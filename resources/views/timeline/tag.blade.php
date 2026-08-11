@extends('layouts.classic')

@php($title = __('%Activity% posts tagged #:tag', ['tag' => $tag]))

@section('title', $title)

@section('content')
    @include('timeline._stylesheets')
    {{-- A reading page: the same OpenPNE 3 timeline shell as the home feed, without its compose box
         — nothing here says which tag a new post would carry. --}}
    <x-classic.parts id="homeAllTimeline" name="homeAllTimeline" :title="$title">
        <div class="timeline">
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
