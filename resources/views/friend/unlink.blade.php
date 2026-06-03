@extends('layouts.classic')

@section('title', __('Remove %friend%'))

@section('content')
    <div class="dparts" id="friend_unlink">
        <div class="partsHeading"><h3>{{ __('Remove %friend%') }}</h3></div>
        <div class="parts">
            <p>{{ __('Remove :name from your %friends%?', ['name' => $target->name]) }}</p>

            <form method="POST" action="{{ route('friend.unlink.submit', $target) }}">
                @csrf
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><button type="submit" class="input_submit">{{ __('Remove %friend%') }}</button></li>
                        <li><a href="{{ route('friend.list') }}">{{ __('Cancel') }}</a></li>
                    </ul>
                </div>
            </form>
        </div>
    </div>
@endsection
