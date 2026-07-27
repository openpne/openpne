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
    @if ($owner->is(auth()->user()))
        {{-- OpenPNE 3 listMemberSuccess.php gives the owner's post entry point its own box above
             the archive. --}}
        <x-classic.parts id="newDiaryLink" name="box" :title="__('Write a %diary%')">
            <div class="body"><a href="{{ route('diary.new') }}">{{ __('Write a %diary%') }}</a></div>
        </x-classic.parts>
    @endif

    @if ($diaries->isEmpty())
        {{-- OpenPNE 3 listMemberSuccess.php swaps the archive for a plain box once the pager is empty. --}}
        <x-classic.parts id="diaryList" name="box">
            <x-slot:heading>
                <h3>{{ $title }}@if ($period) <span class="archivePeriod">{{ $period }}</span>@endif</h3>
            </x-slot:heading>
            <div class="body">{{ __('No %diary% entries to show.') }}</div>
        </x-classic.parts>
    @else
        <x-classic.parts id="diary_list" name="recentList">
            <x-slot:heading>
                <h3>{{ $title }}@if ($period) <span class="archivePeriod">{{ $period }}</span>@endif</h3>
            </x-slot:heading>
            <ul class="diaryList">
                @foreach ($diaries as $entry)
                    <li>
                        <a href="{{ route('diary.show', $entry) }}">{{ \App\Features\Diary\DiaryTitle::withCount($entry) }}</a>
                        <span class="diaryDate">{{ \App\Support\LocalizedDate::dateTime($entry->created_at) }}</span>
                        @if ($owner->is(auth()->user()))
                            <a href="{{ route('diary.edit', $entry) }}">{{ __('Edit') }}</a>
                            <a href="{{ route('diary.delete.show', $entry) }}">{{ __('Delete') }}</a>
                        @endif
                    </li>
                @endforeach
            </ul>

            {{ $diaries->links() }}
        </x-classic.parts>
    @endif
@endsection
