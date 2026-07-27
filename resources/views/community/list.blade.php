@extends('layouts.classic')

@php($isOwner = $owner->is(auth()->user()))
@php($title = $isOwner ? __('My %communities%') : __(":name's %communities%", ['name' => $owner->name]))

@section('title', $title)

@section('content')
    @if ($communities->isEmpty())
        {{-- OpenPNE 3 joinlistError.php swaps the photo table for a plain box once the pager is empty. --}}
        <x-classic.parts id="noJoinCommunity" name="box" :title="$title">
            <div class="body">{{ __('No %communities% to show.') }}</div>
        </x-classic.parts>
    @else
        <x-classic.parts id="communityList" name="photoTable" :title="$title">
            <ul class="communityList">
                @foreach ($communities as $community)
                    <li>
                        <a href="{{ route('community.show', $community) }}">{{ $community->name }}</a>
                    </li>
                @endforeach
            </ul>

            {{ $communities->withQueryString()->links() }}
        </x-classic.parts>
    @endif
@endsection
