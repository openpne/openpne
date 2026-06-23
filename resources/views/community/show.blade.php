@extends('layouts.classic')

@section('title', $community->name)

@section('content')
    <div class="dparts" id="community_profile">
        <div class="partsHeading"><h3>{{ $community->name }}</h3></div>
        <div class="parts">
            @if ($community->image)
                {{-- OpenPNE 3 community home: the top image (communityImageBox), linking to the full bytes. --}}
                <p class="photo"><a href="{{ $community->image->url() }}" target="_blank" rel="noopener"><img src="{{ $community->image->thumbnailUrl(120, 120, square: true) }}" alt=""></a></p>
            @endif
            @if ($community->category)
                <p class="category">{{ $community->category->name }}</p>
            @endif
            @if ($community->description)
                <p class="description">{{ $community->description }}</p>
            @endif
            <p class="memberCount">{{ __(':count members', ['count' => $community->members_count]) }}</p>

            <div class="operation">
                <ul class="moreInfo button">
                    <li><a href="{{ route('community.members', ['id' => $community->getKey()]) }}">{{ __('Member list') }}</a></li>

                    @if ($role === null && ! $isPending)
                        <li><a href="{{ route('community.join.show', ['id' => $community->getKey()]) }}">{{ __('Join this %community%') }}</a></li>
                    @elseif ($isPending)
                        <li><span class="pending">{{ __('Your join request is pending.') }}</span></li>
                    @endif

                    @if ($role?->canManage())
                        <li><a href="{{ route('community.edit', ['id' => $community->getKey()]) }}">{{ __('Edit settings') }}</a></li>
                    @endif
                    @if ($role === \App\Features\Community\CommunityRole::Admin)
                        <li><a href="{{ route('community.members.pending', ['id' => $community->getKey()]) }}">{{ __('Pending members') }}</a></li>
                        <li><a href="{{ route('community.delete.show', $community) }}">{{ __('Delete %community%') }}</a></li>
                    @elseif ($role !== null)
                        <li><a href="{{ route('community.quit.show', ['id' => $community->getKey()]) }}">{{ __('Leave this %community%') }}</a></li>
                    @endif
                </ul>
            </div>
        </div>
    </div>

    {{-- OpenPNE 3 community home: the recent-topics box links into the board. Shown only when the
         viewer may read the board (a members-only board is hidden from non-members). --}}
    @isset($recentTopics)
        <div class="dparts" id="community_recentTopics">
            <div class="partsHeading"><h3>{{ __('Recent %topics%') }}</h3></div>
            <div class="parts">
                @if ($recentTopics->isEmpty())
                    <p>{{ __('No %topics% to show.') }}</p>
                @else
                    <ul class="topicList">
                        @foreach ($recentTopics as $topic)
                            <li>
                                <a href="{{ route('communityTopic.show', $topic) }}">{{ $topic->name }} ({{ $topic->comments_count }})</a>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <div class="operation">
                    <ul class="moreInfo button">
                        <li><a href="{{ route('communityTopic.index', $community) }}">{{ __('See all %topics%') }}</a></li>
                        @if ($canPostTopic)
                            <li><a href="{{ route('communityTopic.new', $community) }}">{{ __('Post a new %topic%') }}</a></li>
                        @endif
                    </ul>
                </div>
            </div>
        </div>
    @endisset

    {{-- OpenPNE 3 community home: the recent-events box links into the event board, shown only when
         the viewer may read the board (events share the topic read gate). --}}
    @isset($recentEvents)
        <div class="dparts" id="community_recentEvents">
            <div class="partsHeading"><h3>{{ __('Recent events') }}</h3></div>
            <div class="parts">
                @if ($recentEvents->isEmpty())
                    <p>{{ __('No events to show.') }}</p>
                @else
                    <ul class="topicList">
                        @foreach ($recentEvents as $event)
                            <li>
                                <a href="{{ route('communityEvent.show', $event) }}">{{ $event->name }} ({{ $event->comments_count }})</a>
                                <span class="eventOpenDate">{{ \App\Support\LocalizedDate::date($event->open_date) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <div class="operation">
                    <ul class="moreInfo button">
                        <li><a href="{{ route('communityEvent.index', $community) }}">{{ __('See all events') }}</a></li>
                        @if ($canPostEvent)
                            <li><a href="{{ route('communityEvent.new', $community) }}">{{ __('Post a new event') }}</a></li>
                        @endif
                    </ul>
                </div>
            </div>
        </div>
    @endisset
@endsection
