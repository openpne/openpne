@extends('layouts.classic')

@section('title', __(':name members', ['name' => $community->name]))

@section('content')
    <div class="dparts" id="community_memberList">
        <div class="partsHeading"><h3>{{ __(':name members', ['name' => $community->name]) }}</h3></div>
        <div class="parts">
            <ul class="memberList">
                @foreach ($members as $membership)
                    <li>
                        <span class="memberName">{{ $membership->member->name }}</span>
                        <span class="role">{{ __($membership->role->label()) }}</span>
                    </li>
                @endforeach
            </ul>

            {{ $members->links() }}
        </div>
    </div>
@endsection
