@extends('layouts.classic')

@php($title = $owner->is(auth()->user()) ? __('%Friends%') : __(":name's %friends%", ['name' => $owner->name]))

@section('title', $title)

@section('content')
    @if ($friends->isEmpty())
        {{-- OpenPNE 3 listError.php swaps the photo table for a plain box once the pager is empty. --}}
        <x-classic.parts id="noFriend" name="box" :title="$title">
            <div class="body">{{ __('No %friends% to show.') }}</div>
        </x-classic.parts>
    @else
        <x-classic.parts id="friendList" name="photoTable" :title="$title">
            <ul class="friendList">
                @foreach ($friends as $friend)
                    <li>
                        <span class="memberName">{{ $friend->name }}</span>
                        @if ($owner->is(auth()->user()))
                            <a href="{{ route('friend.unlink.show', $friend) }}">{{ __('Remove %friend%') }}</a>
                        @endif
                    </li>
                @endforeach
            </ul>

            {{ $friends->links() }}
        </x-classic.parts>
    @endif
@endsection
