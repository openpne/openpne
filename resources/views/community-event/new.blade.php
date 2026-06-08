@extends('layouts.classic')

@section('title', __('Post a new event'))

@section('content')
    <div class="dparts form" id="communityEvent_new">
        <div class="partsHeading"><h3>{{ __('Post a new event') }}</h3></div>
        <div class="parts">
            <form method="POST" action="{{ route('communityEvent.store', $community) }}" enctype="multipart/form-data">
                @csrf
                <table>
                    @include('community-event._fields')
                    @include('community-event._image_fields')
                </table>
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('Post') }}"></li>
                        <li><a href="{{ route('communityEvent.index', $community) }}">{{ __('Cancel') }}</a></li>
                    </ul>
                </div>
            </form>
        </div>
    </div>
@endsection
