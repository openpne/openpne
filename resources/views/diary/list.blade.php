@extends('layouts.classic')

@php($title = $owner->is(auth()->user()) ? __('%Diary%') : __(":name's %diary%", ['name' => $owner->name]))
@php($period = $period ?? null)
@php($archiveStart = $archiveStart ?? null)

@section('title', $title)

@section('sidemenu')
    {{-- Calendar focuses the archived month; the plain listMember view defaults to today. --}}
    <x-diary.sidemenu :member="$owner" :year="$archiveStart?->year" :month="$archiveStart?->month" />
@endsection

@section('content')
    <div class="dparts" id="diary_list">
        <div class="partsHeading"><h3>{{ $title }}@if ($period) <span class="archivePeriod">{{ $period }}</span>@endif</h3></div>
        <div class="parts">
            @if ($diaries->isEmpty())
                <p>{{ __('No %diary% entries to show.') }}</p>
            @else
                <ul class="diaryList">
                    @foreach ($diaries as $entry)
                        <li>
                            <a href="{{ route('diary.show', $entry) }}">{{ $entry->title }}</a>
                            <span class="diaryDate">{{ $entry->created_at->format('Y-m-d') }}</span>
                            @if ($owner->is(auth()->user()))
                                <a href="{{ route('diary.edit', $entry) }}">{{ __('Edit') }}</a>
                                <a href="{{ route('diary.delete.show', $entry) }}">{{ __('Delete') }}</a>
                            @endif
                        </li>
                    @endforeach
                </ul>

                {{ $diaries->links() }}
            @endif

            @if ($owner->is(auth()->user()))
                <p><a href="{{ route('diary.new') }}">{{ __('Write a %diary%') }}</a></p>
            @endif
        </div>
    </div>
@endsection
