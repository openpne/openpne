@extends('layouts.classic')

@php($title = __(':name talk', ['name' => $group->name]))

@section('title', $title)

@section('content')
    @include('timeline._stylesheets')
    @include('timeline._scripts')
    {{-- OpenPNE 3's _timelineCommunity.php: the box it wrote by hand around div.timeline, filled
         client-side from the API; here the rows are rendered server-side, newest first, with the
         pager beside the list as the way on without the script. --}}
    <x-classic.parts id="communityTimeline" name="communityTimeline" :hand-written="true" :title="$title">
        <div class="timeline" data-timeline-container>
            @if ($canPost)
                @include('group-talk._compose')
            @endif
            <div id="timeline-list">
                @foreach ($messages as $message)
                    @include('group-talk._message', ['message' => $message])
                @endforeach
            </div>
            @if ($loadMoreUrl !== null)
                @include('timeline._loadmore', ['nextUrl' => $loadMoreUrl])
            @endif
        </div>
        @if ($messages->isEmpty())
            <p>{{ $newerUrl !== null ? __('No older posts.') : __('No messages yet.') }}</p>
        @endif
        <div data-timeline-pager><x-classic.stream-pager :older-url="$olderUrl" :newer-url="$newerUrl" /></div>
        @if ($canPost)
            <div class="operation">
                <form method="POST" action="{{ route('group.talk.mute', $group) }}">
                    @csrf
                    <input type="hidden" name="muted" value="{{ $isMuted ? '0' : '1' }}">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ $isMuted ? __('Unmute') : __('Mute') }}"></li>
                    </ul>
                </form>
            </div>
        @endif
    </x-classic.parts>
@endsection
