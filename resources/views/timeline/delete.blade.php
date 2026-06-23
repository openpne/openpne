@extends('layouts.classic')

@section('title', __('Delete post'))

@section('content')
    <div class="dparts" id="timeline_delete">
        <div class="partsHeading"><h3>{{ __('Delete post') }}</h3></div>
        <div class="parts">
            <p>{{ __('Delete this post?') }}</p>
            <form method="POST" action="{{ route('timeline.delete', $post) }}">
                @csrf
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('Delete') }}"></li>
                        <li><a href="{{ route('timeline.show', $post) }}">{{ __('Cancel') }}</a></li>
                    </ul>
                </div>
            </form>
        </div>
    </div>
@endsection
