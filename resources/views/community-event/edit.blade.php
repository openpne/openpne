@extends('layouts.classic')

@section('title', __('Edit event'))

@section('content')
    <div class="dparts form" id="communityEvent_edit">
        <div class="partsHeading"><h3>{{ __('Edit event') }}</h3></div>
        <div class="parts">
            <form method="POST" action="{{ route('communityEvent.update', $event) }}">
                @csrf
                <table>
                    @include('community-event._fields', ['event' => $event])
                </table>
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('Save') }}"></li>
                        <li><a href="{{ route('communityEvent.show', $event) }}">{{ __('Cancel') }}</a></li>
                    </ul>
                </div>
            </form>
        </div>
    </div>
@endsection
