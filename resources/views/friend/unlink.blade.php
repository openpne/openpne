@extends('layouts.classic')

@section('title', __('Unfriend'))

@section('content')
    <div class="dparts" id="friend_unlink">
        <h2 class="partsHeading">{{ __('Unfriend') }}</h2>
        <div class="parts">
            <p>{{ __('Remove :name from your friends?', ['name' => $target->name]) }}</p>

            <form method="POST" action="{{ route('friend.unlink.submit', $target) }}">
                @csrf
                <button type="submit">{{ __('Unfriend') }}</button>
                <a href="{{ route('friend.list') }}">{{ __('Cancel') }}</a>
            </form>
        </div>
    </div>
@endsection
