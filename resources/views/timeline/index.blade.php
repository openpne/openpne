@extends('layouts.classic')

@php($title = __('%Activity%'))

@section('title', $title)

@section('content')
    {{-- OpenPNE 3 streams posts client-side from the API; the Classic adapter renders them
         server-side with a pager. It served this feed only as the homeAllTimeline gadget, whose id
         carries a gadget suffix; the standalone page keeps the bare kind name as its id. --}}
    <x-classic.parts id="homeAllTimeline" name="homeAllTimeline" :title="$title">
        <p><a href="{{ route('timeline.new') }}">{{ __('%Post_activity%') }}</a></p>

        @if ($posts->isEmpty())
            <p>{{ __('No %activity% posts to show.') }}</p>
        @else
            <ul class="timeline-list">
                @foreach ($posts as $post)
                    @include('timeline._post', ['post' => $post])
                @endforeach
            </ul>

            {{ $posts->links() }}
        @endif
    </x-classic.parts>
@endsection
