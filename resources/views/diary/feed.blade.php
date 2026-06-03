@extends('layouts.classic')

@php($title = $scope === 'friends' ? __('%Diaries% of %My_friends%') : __('Recently Posted %Diaries%'))

@section('title', $title)

@section('content')
    <div class="dparts" id="diary_feed">
        <div class="partsHeading"><h3>{{ $title }}</h3></div>
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
