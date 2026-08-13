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
        @php
            $items = $participants->map(fn ($member) => [
                'url' => route('member.profile.show', $member),
                'file' => $member->avatar?->file,
                'name' => $member->name,
                'count' => $member->friendships_count,
            ])->all();
        @endphp

        <x-classic.parts id="communityEventMembersList" name="photoTable" :title="__('Event Members')">
            <x-classic.photo-table :items="$items" :paginator="$participants->withQueryString()" />
        </x-classic.parts>
    @endif

    <x-classic.parts name="line">
        <a href="{{ route('group.events.show', $event) }}">{{ $event->name }}</a>
    </x-classic.parts>
@endsection
