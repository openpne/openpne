@extends('layouts.classic')

@section('title', $community->name)

@section('content')
    <div class="dparts" id="communityTopic_board">
        <div class="partsHeading"><h3>{{ $community->name }}</h3></div>
        <div class="parts">
            @if ($canPost)
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><a href="{{ route('communityTopic.new', $community) }}">{{ __('Post a new %topic%') }}</a></li>
                    </ul>
                </div>
            @endif

            @if ($topics->isEmpty())
                <p>{{ __('No %topics% to show.') }}</p>
            @else
                <ul class="topicList">
                    @foreach ($topics as $topic)
                        <li>
                            {{-- OpenPNE 3 listCommunitySuccess: last-activity datetime + name (comment count). --}}
                            <span class="topicDate">{{ \App\Support\LocalizedDate::dateTime($topic->updated_at) }}</span>
                            <a href="{{ route('communityTopic.show', $topic) }}">{{ $topic->name }} ({{ $topic->comments_count }})</a>
                            @if ($topic->member)
                                <span class="topicAuthor">{{ $topic->member->name }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>

                {{ $topics->withQueryString()->links() }}
            @endif
        </div>
    </div>

    <div class="line">
        <a href="{{ route('community.show', $community) }}">{{ $community->name }}</a>
    </div>
@endsection
