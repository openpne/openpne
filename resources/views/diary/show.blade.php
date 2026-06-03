@extends('layouts.classic')

@section('title', $diary->title)

@section('content')
    <div class="dparts" id="diary_show">
        <div class="partsHeading"><h3>{{ $diary->title }}</h3></div>
        <div class="parts">
            <p class="diaryMeta">
                {{ $diary->member->name }} &mdash; {{ $diary->created_at->format('Y-m-d H:i') }}
            </p>
            <div class="diaryBody">{{ $diary->body }}</div>

            @if ($diary->member->is(auth()->user()))
                <p>
                    <a href="{{ route('diary.edit', $diary) }}">{{ __('Edit') }}</a>
                    <a href="{{ route('diary.delete.show', $diary) }}">{{ __('Delete') }}</a>
                </p>
            @endif
        </div>
    </div>
@endsection
