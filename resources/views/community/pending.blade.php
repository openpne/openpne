@extends('layouts.classic')

@section('title', __('Pending members'))

@section('content')
    {{-- Join approvals, which OpenPNE 3 served from confirmation/list rather than per community, so
         this box has no OpenPNE 3 kind or id to restore. --}}
    <x-classic.parts id="community_memberManage" :title="__('Pending members')">
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
    </x-classic.parts>
@endsection
