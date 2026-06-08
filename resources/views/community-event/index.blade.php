@extends('layouts.classic')

@section('title', $community->name)

@section('content')
    <div class="dparts" id="communityEvent_board">
        <div class="partsHeading"><h3>{{ $community->name }}</h3></div>
        <div class="parts">
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
        </div>
    </div>

    <div class="line">
        <a href="{{ route('community.show', $community) }}">{{ $community->name }}</a>
    </div>
@endsection
