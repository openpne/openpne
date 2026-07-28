@extends('layouts.classic')

@php
    $isOwner = $owner->is(auth()->user());
    $title = $isOwner ? __('My %communities%') : __(":name's %communities%", ['name' => $owner->name]);
    $items = $communities->map(fn ($community) => [
        'url' => route('community.show', $community),
        'file' => $community->image,
        'name' => $community->name,
        'count' => $community->members_count,
        'crown' => $community->owner_is_admin,
    ])->all();
@endphp

@section('title', $title)

@section('content')
    @if ($communities->isEmpty())
        {{-- OpenPNE 3 joinlistError.php swaps the photo table for a plain box once the pager is empty. --}}
        <x-classic.parts id="noJoinCommunity" name="box" :title="$title">
            <div class="body">{{ __('No %communities% to show.') }}</div>
        </x-classic.parts>
    @else
        <x-classic.parts id="communityList" name="photoTable" :title="$title">
            <x-classic.photo-table :items="$items" :paginator="$communities->withQueryString()" />
        </x-classic.parts>
    @endif
@endsection
