@extends('layouts.classic')

@section('title', __('Delete event'))

@section('content')
    <div class="dparts box" id="communityEvent_delete">
        <div class="partsHeading"><h3>{{ __('Delete event') }}</h3></div>
        <div class="parts">
            <div class="block">
                <p>{{ __('Delete :name? This cannot be undone.', ['name' => $event->name]) }}</p>
                <form method="POST" action="{{ route('communityEvent.delete', $event) }}">
                    @csrf
                    <div class="operation">
                        <ul class="moreInfo button">
                            <li><input type="submit" class="input_submit" value="{{ __('Delete') }}"></li>
                            <li><a href="{{ route('communityEvent.show', $event) }}">{{ __('Cancel') }}</a></li>
                        </ul>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
