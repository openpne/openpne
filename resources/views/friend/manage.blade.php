@extends('layouts.classic')

@section('title', __('Pending %friend% requests'))

@section('content')
    {{-- Pending requests, which OpenPNE 3 served from confirmation/list, not friend/manage (that was
         the friend list with unlink links, folded into friend/list here) — so no OpenPNE 3 kind or id
         applies to these two boxes. --}}
    <x-classic.parts id="friend_manage_received" :title="__('Requests received')">
        @if ($received->isEmpty())
            <p>{{ __('No pending requests.') }}</p>
        @else
            <ul class="requestList">
                @foreach ($received as $requester)
                    <li>
                        <span class="memberName">{{ $requester->name }}</span>
                        <form method="POST" action="{{ route('friend.accept') }}" class="inline">
                            @csrf
                            <input type="hidden" name="requester_id" value="{{ $requester->getKey() }}">
                            <input type="submit" class="input_submit" value="{{ __('Accept') }}">
                        </form>
                        <form method="POST" action="{{ route('friend.reject') }}" class="inline">
                            @csrf
                            <input type="hidden" name="requester_id" value="{{ $requester->getKey() }}">
                            <input type="submit" class="input_submit" value="{{ __('Reject') }}">
                        </form>
                    </li>
                @endforeach
            </ul>

            {{ $received->links() }}
        @endif
    </x-classic.parts>

    <x-classic.parts id="friend_manage_sent" :title="__('Requests sent')">
        @if ($sent->isEmpty())
            <p>{{ __('No outgoing requests.') }}</p>
        @else
            <ul class="requestList">
                @foreach ($sent as $target)
                    <li>
                        <span class="memberName">{{ $target->name }}</span>
                    </li>
                @endforeach
            </ul>

            {{ $sent->links() }}
        @endif
    </x-classic.parts>
@endsection
