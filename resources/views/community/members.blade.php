@extends('layouts.classic')

@section('title', __(':name members', ['name' => $community->name]))

@section('content')
    <x-classic.parts id="communityMembersList" name="photoTable" :title="__(':name members', ['name' => $community->name])">
        <ul class="memberList">
            @foreach ($members as $membership)
                <li>
                    <span class="memberName">{{ $membership->member->name }}</span>
                    <span class="role">{{ __($membership->role->label()) }}</span>
                </li>
            @endforeach
        </ul>

        {{ $members->links() }}
    </x-classic.parts>
@endsection
