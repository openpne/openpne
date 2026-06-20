@extends('layouts.classic')

@section('title', __('Home'))

@php($zones = $zones ?? [])
@php($hasGadgets = collect($zones)->flatten(1)->isNotEmpty())

@if ($hasGadgets)
    @include('partials.gadget-sections', ['zones' => $zones])
@else
    @section('content')
        {{-- No gadgets configured yet: a minimal landing until the admin adds gadgets. --}}
        <div class="dparts" id="home_index">
            <div class="partsHeading"><h3>{{ __('Home') }}</h3></div>
            <div class="parts">
                <p>{{ __('Welcome, :name.', ['name' => auth()->user()->name]) }}</p>
                <ul>
                    <li><a href="{{ route('diary.list_member') }}">{{ __('%Diary%') }}</a></li>
                    <li><a href="{{ route('friend.list') }}">{{ __('%Friends%') }}</a></li>
                    <li><a href="{{ route('member.search') }}">{{ __('Member search') }}</a></li>
                    <li><a href="{{ route('member.profile.mine_compat') }}">{{ __('My profile') }}</a></li>
                </ul>
            </div>
        </div>
    @endsection
@endif
