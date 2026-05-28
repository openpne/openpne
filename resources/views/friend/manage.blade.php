@extends('layouts.classic')

@section('title', 'Pending friend requests')

@section('content')
    <div class="dparts" id="friend_manage_received">
        <h2 class="partsHeading">Requests received</h2>
        <div class="parts">
            @if ($received->isEmpty())
                <p>No pending requests.</p>
            @else
                <ul class="requestList">
                    @foreach ($received as $requester)
                        <li>
                            <span class="memberName">{{ $requester->name }}</span>
                            <form method="POST" action="{{ route('friend.accept') }}" class="inline">
                                @csrf
                                <input type="hidden" name="requester_id" value="{{ $requester->getKey() }}">
                                <button type="submit">Accept</button>
                            </form>
                            <form method="POST" action="{{ route('friend.reject') }}" class="inline">
                                @csrf
                                <input type="hidden" name="requester_id" value="{{ $requester->getKey() }}">
                                <button type="submit">Reject</button>
                            </form>
                        </li>
                    @endforeach
                </ul>

                {{ $received->links() }}
            @endif
        </div>
    </div>

    <div class="dparts" id="friend_manage_sent">
        <h2 class="partsHeading">Requests sent</h2>
        <div class="parts">
            @if ($sent->isEmpty())
                <p>No outgoing requests.</p>
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
        </div>
    </div>
@endsection
