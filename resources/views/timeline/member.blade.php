@extends('layouts.classic')

@php($title = $owner->is(auth()->user()) ? __('%Activity%') : __(":name's %activity%", ['name' => $owner->name]))

@section('title', $title)

@section('content')
    @include('timeline._stylesheets')
    {{-- OpenPNE 3 streams the posts client-side from the API; the Classic adapter renders them
         server-side with a pager. The id is _timelineProfile.php's non-gadget branch, which suffixes
         the member id (the gadget branch suffixes the gadget id into the same prefix). --}}
    <x-classic.parts :id="'profileTimeline_'.$owner->getKey()" name="profileTimeline" :title="$title">
        @if ($owner->is(auth()->user()))
            <p><a href="{{ route('timeline.new') }}">{{ __('%Post_activity%') }}</a></p>
        @endif

        @if ($posts->isEmpty())
            <p>{{ __('No %activity% posts to show.') }}</p>
        @else
            {{-- OpenPNE 3's div.timeline > div#timeline-list shell; the load-more button becomes a
                 server pager, the Classic list idiom. --}}
            <div class="timeline">
                <div id="timeline-list">
                    @foreach ($posts as $post)
                        @include('timeline._post', ['post' => $post])
                    @endforeach
                </div>
            </div>

            <x-classic.pager :paginator="$posts" />
        @endif
    </x-classic.parts>
@endsection
