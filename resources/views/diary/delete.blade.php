@extends('layouts.classic')

@section('title', __('Delete %diary%'))

@section('content')
    <div class="dparts" id="diary_delete">
        <div class="partsHeading"><h3>{{ __('Delete %diary%') }}</h3></div>
        <div class="parts">
            <p>{{ __('Delete ":title"?', ['title' => $diary->title]) }}</p>
            <form method="POST" action="{{ route('diary.delete', $diary) }}">
                @csrf
                <button type="submit">{{ __('Delete') }}</button>
                <a href="{{ route('diary.show', $diary) }}">{{ __('Cancel') }}</a>
            </form>
        </div>
    </div>
@endsection
