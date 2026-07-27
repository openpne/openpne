@extends('layouts.classic')

@section('title', __('Event Members'))

@section('content')
    @if ($participants->isEmpty())
        {{-- OpenPNE 3 memberListError.php swaps the photo table for a plain box once nobody has
             joined. --}}
        <x-classic.parts id="noMembers" name="box" :title="__('Event Members')">
            <div class="body">{{ __('No members to show.') }}</div>
        </x-classic.parts>
    @else
        <x-classic.parts id="communityEventMembersList" name="photoTable" :title="__('Event Members')">
            <ul class="memberList">
                @foreach ($participants as $member)
                    <li><a href="{{ route('member.profile.show', $member) }}">{{ $member->name }}</a></li>
                @endforeach
            </ul>

            {{ $participants->withQueryString()->links() }}
        </x-classic.parts>
    @endif

    <x-classic.parts name="line">
        <a href="{{ route('communityEvent.show', $event) }}">{{ $event->name }}</a>
    </x-classic.parts>
@endsection
