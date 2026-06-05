@extends('layouts.classic')

@section('title', __('Leave this %community%'))

@section('content')
    <div class="dparts" id="community_quit">
        <div class="partsHeading"><h3>{{ __('Leave this %community%') }}</h3></div>
        <div class="parts">
            <p>{{ __('Leave :name?', ['name' => $community->name]) }}</p>

            <form method="POST" action="{{ route('community.quit') }}">
                @csrf
                <input type="hidden" name="id" value="{{ $community->getKey() }}">
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('Leave this %community%') }}"></li>
                        <li><a href="{{ route('community.show', $community) }}">{{ __('Cancel') }}</a></li>
                    </ul>
                </div>
            </form>
        </div>
    </div>
@endsection
