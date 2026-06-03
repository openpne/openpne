@extends('layouts.classic')

@section('title', __('Pending %friend% requests'))

@section('content')
    <div class="dparts" id="friend_manage_received">
        <div class="partsHeading"><h3>{{ __('Requests received') }}</h3></div>
        <div class="parts">
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
                                <button type="submit" class="input_submit">{{ __('Accept') }}</button>
                            </form>
                            <form method="POST" action="{{ route('friend.reject') }}" class="inline">
                                @csrf
                                <input type="hidden" name="requester_id" value="{{ $requester->getKey() }}">
                                <button type="submit" class="input_submit">{{ __('Reject') }}</button>
                            </form>
                        </li>
                    @endforeach
                </ul>

                {{ $received->links() }}
            @endif
        </div>
    </div>

    <div class="dparts" id="friend_manage_sent">
        <div class="partsHeading"><h3>{{ __('Requests sent') }}</h3></div>
        <div class="parts">
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
        </div>
    </div>
@endsection
