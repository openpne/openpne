@extends('layouts.classic')

@section('title', __('Home'))

@php($zones = $zones ?? [])
@php($hasGadgets = collect($zones)->flatten(1)->isNotEmpty())

@if ($hasGadgets)
    @if (! empty($zones['top'] ?? []))
        @section('top')<x-gadget-zone :items="$zones['top']" />@endsection
    @endif
    @if (! empty($zones['sideMenu'] ?? []))
        @section('sidemenu')<x-gadget-zone :items="$zones['sideMenu']" />@endsection
    @endif
    @section('content')<x-gadget-zone :items="$zones['contents'] ?? []" />@endsection
    @if (! empty($zones['bottom'] ?? []))
        @section('bottom')<x-gadget-zone :items="$zones['bottom']" />@endsection
    @endif
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
