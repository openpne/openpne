@extends('layouts.classic')

@section('title', __('Event Members'))

@section('content')
    <div class="dparts" id="communityEvent_memberList">
        <div class="partsHeading"><h3>{{ __('Event Members') }}</h3></div>
        <div class="parts">
            @if ($participants->isEmpty())
                <p>{{ __('No members to show.') }}</p>
            @else
                <ul class="memberList">
                    @foreach ($participants as $member)
                        <li><a href="{{ route('member.profile.show', $member) }}">{{ $member->name }}</a></li>
                    @endforeach
                </ul>

                {{ $participants->withQueryString()->links() }}
            @endif
        </div>
    </div>

    <div class="line">
        <a href="{{ route('communityEvent.show', $event) }}">{{ $event->name }}</a>
    </div>
@endsection
