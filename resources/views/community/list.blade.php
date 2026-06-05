@extends('layouts.classic')

@php($isOwner = $owner->is(auth()->user()))
@php($title = $isOwner ? __('My %communities%') : __(":name's %communities%", ['name' => $owner->name]))

@section('title', $title)

@section('content')
    <div class="dparts" id="community_joinlist">
        <div class="partsHeading"><h3>{{ $title }}</h3></div>
        <div class="parts">
            @if ($communities->isEmpty())
                <p>{{ __('No %communities% to show.') }}</p>
            @else
                <ul class="communityList">
                    @foreach ($communities as $community)
                        <li>
                            <a href="{{ route('community.show', $community) }}">{{ $community->name }}</a>
                        </li>
                    @endforeach
                </ul>

                {{ $communities->withQueryString()->links() }}
            @endif
        </div>
    </div>
@endsection
