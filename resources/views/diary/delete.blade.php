@extends('layouts.classic')

@section('title', __('Delete %diary%'))

@section('content')
    <div class="dparts" id="diary_delete">
        <div class="partsHeading"><h3>{{ __('Delete %diary%') }}</h3></div>
        <div class="parts">
            <p>{{ __('Delete ":title"?', ['title' => $diary->title]) }}</p>
            <form method="POST" action="{{ route('diary.delete', $diary) }}">
                @csrf
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('Delete') }}"></li>
                        <li><a href="{{ route('diary.show', $diary) }}">{{ __('Cancel') }}</a></li>
                    </ul>
                </div>
            </form>
        </div>
    </div>
@endsection
