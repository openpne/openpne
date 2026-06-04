@extends('layouts.classic')

@php
    $searchable = $variant !== 'friends';
    $title = match (true) {
        $variant === 'friends' => __('%Diaries% of %My_friends%'),
        $variant === 'search' && $hasKeyword => __('Search Results'),
        default => __('Recently Posted %Diaries%'),
    };
@endphp

@section('title', $title)

@section('content')
    @if ($searchable)
        <div id="diarySearchFormLine" class="parts searchFormLine">
            <form method="GET" action="{{ route('diary.search') }}">
                <p class="form">
                    <input id="keyword" type="text" class="input_text" name="keyword" size="30" value="{{ $keyword }}">
                    <input type="submit" class="input_submit" value="{{ __('Search') }}">
                </p>
            </form>
        </div>
    @endif

    <div class="dparts" id="diary_feed">
        <div class="partsHeading"><h3>{{ $title }}</h3></div>
        <div class="parts">
            @if ($diaries->isEmpty())
                <p>{{ __('No %diary% entries to show.') }}</p>
            @else
                <ul class="diaryList">
                    @foreach ($diaries as $entry)
                        <li>
                            {{-- OpenPNE 3 op_diary_get_title_and_count: title followed by the comment count. --}}
                            <a href="{{ route('diary.show', $entry) }}">{{ $entry->title }} ({{ $entry->comments_count }})</a>
                            <span class="diaryAuthor">{{ $entry->member->name }}</span>
                            <span class="diaryDate">{{ $entry->created_at->format('Y-m-d') }}</span>
                        </li>
                    @endforeach
                </ul>

                {{ $diaries->links() }}
            @endif
        </div>
    </div>
@endsection
