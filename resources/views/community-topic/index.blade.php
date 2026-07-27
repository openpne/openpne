@extends('layouts.classic')

@section('title', $community->name)

@section('content')
    {{-- OpenPNE 3 listCommunitySuccess.php lists the board in a recentList box (no id) and puts the
         create entry point in a buttonBox above it; OpenPNE 4 folds that button in here. --}}
    <x-classic.parts id="communityTopic_board" name="recentList" :title="$community->name">
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
    </x-classic.parts>

    <x-classic.parts name="line">
        <a href="{{ route('community.show', $community) }}">{{ $community->name }}</a>
    </x-classic.parts>
@endsection
