@extends('layouts.classic')

@section('title', $owner->is(auth()->user()) ? 'Friends' : $owner->name . "'s friends")

@section('content')
    <div class="dparts" id="friend_list">
        <h2 class="partsHeading">
            {{ $owner->is(auth()->user()) ? 'Friends' : $owner->name . "'s friends" }}
        </h2>
        <div class="parts">
            @if ($friends->isEmpty())
                <p>No friends to show.</p>
            @else
                <ul class="friendList">
                    @foreach ($friends as $friend)
                        <li>
                            <span class="memberName">{{ $friend->name }}</span>
                            @if ($owner->is(auth()->user()))
                                <a href="{{ route('friend.unlink.show', $friend) }}">Unfriend</a>
                            @endif
                        </li>
                    @endforeach
                </ul>

                {{ $friends->links() }}
            @endif
        </div>
    </div>
@endsection
