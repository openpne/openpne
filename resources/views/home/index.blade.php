@extends('layouts.classic')

@section('title', __('Home'))

@php($zones = $zones ?? [])
@php($hasGadgets = collect($zones)->flatten(1)->isNotEmpty())

@if ($hasGadgets)
    @include('partials.gadget-sections', ['zones' => $zones, 'contentTop' => 'home.partials.cautions'])
@else
    @section('content')
        @include('home.partials.cautions')
        {{-- No gadgets configured yet: a minimal landing until the admin adds gadgets. OpenPNE 3
             leaves the column empty instead, so there is no kind to reproduce. --}}
        <x-classic.parts id="home_index" :title="__('Home')">
            <p>{{ __('Welcome, :name.', ['name' => auth()->user()->name]) }}</p>
            <ul>
                <li><a href="{{ route('diary.list_member') }}">{{ __('%Diary%') }}</a></li>
                <li><a href="{{ route('friend.list') }}">{{ __('%Friends%') }}</a></li>
                <li><a href="{{ route('member.search') }}">{{ __('Member search') }}</a></li>
                <li><a href="{{ route('member.profile.mine_compat') }}">{{ __('My profile') }}</a></li>
            </ul>
        </x-classic.parts>
    @endsection
@endif
