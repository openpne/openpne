@extends('layouts.classic')

@section('title', $community->name)

@section('content')
    {{-- OpenPNE 3 listCommunitySuccess.php lists the board in a recentList box (no id) and puts the
         create entry point in a buttonBox above it; OpenPNE 4 folds that button in here. --}}
    <x-classic.parts id="communityEvent_board" name="recentList" :title="$community->name">
        @if ($canPost)
            <div class="operation">
                <ul class="moreInfo button">
                    <li><a href="{{ route('communityEvent.new', $community) }}">{{ __('Post a new event') }}</a></li>
                </ul>
            </div>
        @endif

        @if ($events->isEmpty())
            <p>{{ __('No events to show.') }}</p>
        @else
            <ul class="topicList">
                @foreach ($events as $event)
                    <li>
                        {{-- OpenPNE 3 listCommunitySuccess: last-activity datetime + name (comment count). --}}
                        <span class="topicDate">{{ \App\Support\LocalizedDate::dateTime($event->updated_at) }}</span>
                        <a href="{{ route('communityEvent.show', $event) }}">{{ $event->name }} ({{ $event->comments_count }})</a>
                        <span class="eventOpenDate">{{ \App\Support\LocalizedDate::date($event->open_date) }}</span>
                        @if ($event->member)
                            <span class="topicAuthor">{{ $event->member->name }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>

            {{ $events->withQueryString()->links() }}
        @endif
    </x-classic.parts>

    <x-classic.parts name="line">
        <a href="{{ route('community.show', $community) }}">{{ $community->name }}</a>
    </x-classic.parts>
@endsection
