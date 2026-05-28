@extends('layouts.classic')

@php($title = $owner->is(auth()->user()) ? __('Friends') : __(":name's friends", ['name' => $owner->name]))

@section('title', $title)

@section('content')
    <div class="dparts" id="friend_list">
        <h2 class="partsHeading">{{ $title }}</h2>
        <div class="parts">
            @if ($friends->isEmpty())
                <p>{{ __('No friends to show.') }}</p>
            @else
                <ul class="friendList">
                    @foreach ($friends as $friend)
                        <li>
                            <span class="memberName">{{ $friend->name }}</span>
                            @if ($owner->is(auth()->user()))
                                <a href="{{ route('friend.unlink.show', $friend) }}">{{ __('Unfriend') }}</a>
                            @endif
                        </li>
                    @endforeach
                </ul>

                {{ $friends->links() }}
            @endif
        </div>
    </div>
@endsection
