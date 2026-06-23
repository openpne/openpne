@extends('layouts.classic')

@php($title = __('%Activity%'))

@section('title', $title)

@section('content')
    {{-- OpenPNE 3 SNS-wide timeline (homeAllTimeline gadget, _timelineAll.php). OpenPNE 3 streams
         posts client-side from the API; the Classic adapter renders them server-side with a pager. --}}
    <div class="dparts homeAllTimeline" id="homeAllTimeline">
        <div class="partsHeading"><h3>{{ $title }}</h3></div>
        <div class="parts">
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
        </div>
    </div>
@endsection
