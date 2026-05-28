@extends('layouts.classic')

@section('title', 'Unfriend')

@section('content')
    <div class="dparts" id="friend_unlink">
        <h2 class="partsHeading">Unfriend</h2>
        <div class="parts">
            <p>Remove <strong>{{ $target->name }}</strong> from your friends?</p>

            <form method="POST" action="{{ route('friend.unlink.submit', $target) }}">
                @csrf
                <button type="submit">Unfriend</button>
                <a href="{{ route('friend.list') }}">Cancel</a>
            </form>
        </div>
    </div>
@endsection
