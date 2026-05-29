@extends('layouts.classic')

@section('title', __('Delete %diary%'))

@section('content')
    <div class="dparts" id="diary_delete">
        <h2 class="partsHeading">{{ __('Delete %diary%') }}</h2>
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
