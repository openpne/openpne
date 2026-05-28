@extends('layouts.classic')

@section('title', __('Send a friend request'))

@section('content')
    <div class="dparts" id="friend_link">
        <h2 class="partsHeading">{{ __('Send a friend request') }}</h2>
        <div class="parts">
            <p>{{ __('Send a friend request to :name?', ['name' => $target->name]) }}</p>

            <form method="POST" action="{{ route('friend.link') }}">
                @csrf
                <input type="hidden" name="target_id" value="{{ $target->getKey() }}">
                <button type="submit">{{ __('Send request') }}</button>
                <a href="{{ route('friend.list') }}">{{ __('Cancel') }}</a>
            </form>
        </div>
    </div>
@endsection
