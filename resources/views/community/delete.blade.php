@extends('layouts.classic')

@section('title', __('Delete %community%'))

@section('content')
    <div class="dparts" id="community_delete">
        <div class="partsHeading"><h3>{{ __('Delete %community%') }}</h3></div>
        <div class="parts">
            <p>{{ __('Delete :name? This cannot be undone.', ['name' => $community->name]) }}</p>

            <form method="POST" action="{{ route('community.delete', $community) }}">
                @csrf
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('Delete %community%') }}"></li>
                        <li><a href="{{ route('community.show', $community) }}">{{ __('Cancel') }}</a></li>
                    </ul>
                </div>
            </form>
        </div>
    </div>
@endsection
