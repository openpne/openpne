@extends('layouts.classic')

@section('title', __('Pending members'))

@section('content')
    <div class="dparts" id="community_memberManage">
        <div class="partsHeading"><h3>{{ __('Pending members') }}</h3></div>
        <div class="parts">
            @if ($applicants->isEmpty())
                <p>{{ __('No pending requests.') }}</p>
            @else
                <ul class="requestList">
                    @foreach ($applicants as $applicant)
                        <li>
                            <span class="memberName">{{ $applicant->name }}</span>
                            <form method="POST" action="{{ route('community.members.approve', ['id' => $community->getKey()]) }}" class="inline">
                                @csrf
                                <input type="hidden" name="member_id" value="{{ $applicant->getKey() }}">
                                <input type="submit" class="input_submit" value="{{ __('Approve') }}">
                            </form>
                            <form method="POST" action="{{ route('community.members.decline', ['id' => $community->getKey()]) }}" class="inline">
                                @csrf
                                <input type="hidden" name="member_id" value="{{ $applicant->getKey() }}">
                                <input type="submit" class="input_submit" value="{{ __('Decline') }}">
                            </form>
                        </li>
                    @endforeach
                </ul>

                {{ $applicants->links() }}
            @endif
        </div>
    </div>
@endsection
