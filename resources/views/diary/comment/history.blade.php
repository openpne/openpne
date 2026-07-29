@extends('layouts.classic')

@section('title', __('%Diary% Comment History'))

@section('content')
    @if ($diaries->isEmpty())
        {{-- historySuccess.php's empty branch: op_include_box('diaryList', ...). --}}
        <x-classic.parts id="diaryList" name="box" :title="__('%Diary% Comment History')">
            <div class="body">{{ __('There are no %diaries%.') }}</div>
        </x-classic.parts>
    @else
        {{-- historySuccess.php: one recentList (no id on its frame), the pager above and below,
             one dl per diary — the last comment's datetime in the dt, the diary link with its
             comment count and author in the dd (op_diary_link_to_show withName, no camera). --}}
        <x-classic.parts name="recentList" :title="__('%Diary% Comment History')">
            <x-classic.pager :paginator="$diaries" />
            @foreach ($diaries as $diary)
                <dl>
                    <dt>{{ \App\Support\LocalizedDate::dateTime(\Illuminate\Support\Carbon::parse($diary->last_comment_time)) }}</dt>
                    <dd><a href="{{ route('diary.show', $diary) }}">{{ $diary->title }} ({{ $diary->comments_count }})</a> ({{ $diary->member->name }})</dd>
                </dl>
            @endforeach
            <x-classic.pager :paginator="$diaries" />
        </x-classic.parts>
    @endif
@endsection
