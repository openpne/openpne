@extends('layouts.classic')

@section('title', __('Remove %friend%'))

@section('content')
    <div class="dparts" id="friend_unlink">
        <div class="partsHeading"><h3>{{ __('Remove %friend%') }}</h3></div>
        <div class="parts">
            <p>{{ __('Remove :name from your %friends%?', ['name' => $target->name]) }}</p>

            <form method="POST" action="{{ route('friend.unlink.submit', $target) }}">
                @csrf
                <button type="submit">{{ __('Remove %friend%') }}</button>
                <a href="{{ route('friend.list') }}">{{ __('Cancel') }}</a>
            </form>
        </div>
    </div>
@endsection
