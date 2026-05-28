@extends('layouts.classic')

@section('title', 'Send friend request')

@section('content')
    <div class="dparts" id="friend_link">
        <h2 class="partsHeading">Send a friend request</h2>
        <div class="parts">
            <p>Send a friend request to <strong>{{ $target->name }}</strong>?</p>

            <form method="POST" action="{{ route('friend.link') }}">
                @csrf
                <input type="hidden" name="target_id" value="{{ $target->getKey() }}">
                <button type="submit">Send request</button>
                <a href="{{ route('friend.list') }}">Cancel</a>
            </form>
        </div>
    </div>
@endsection
