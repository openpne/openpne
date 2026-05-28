@extends('layouts.classic')

@section('title', 'Friends')

@section('content')
    <div class="dparts" id="friend_list">
        <h2 class="partsHeading">Friends</h2>
        <div class="parts">
            @if ($friends->isEmpty())
                <p>You have no friends yet.</p>
            @else
                <ul class="friendList">
                    @foreach ($friends as $friend)
                        <li>
                            <span class="memberName">{{ $friend->name }}</span>
                            <a href="{{ route('friend.unlink.show', $friend) }}">Unfriend</a>
                        </li>
                    @endforeach
                </ul>

                {{ $friends->links() }}
            @endif
        </div>
    </div>
@endsection
