@extends('layouts.classic')

@section('title', __('Search %diaries%'))

@section('content')
    <div class="dparts form" id="diary_search">
        <div class="partsHeading"><h3>{{ __('Search %diaries%') }}</h3></div>
        <div class="parts">
            <form method="GET" action="{{ route('diary.search') }}">
                <p class="form">
                    <label for="diary_search_keyword">{{ __('Keyword') }}</label>
                    <input type="text" class="input_text" id="diary_search_keyword" name="keyword" value="{{ $keyword }}">
                    <input type="submit" class="input_submit" value="{{ __('Search') }}">
                </p>
            </form>
        </div>
    </div>

    <div class="dparts" id="diary_search_result">
        <div class="partsHeading"><h3>{{ __('Results') }}</h3></div>
        <div class="parts">
            @if ($diaries->isEmpty())
                <p>{{ __('No %diary% entries to show.') }}</p>
            @else
                <ul class="diaryList">
                    @foreach ($diaries as $entry)
                        <li>
                            <a href="{{ route('diary.show', $entry) }}">{{ $entry->title }}</a>
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
