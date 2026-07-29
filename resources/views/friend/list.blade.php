@extends('layouts.classic')

@php
    $isOwner = $owner->is(auth()->user());
    $title = $isOwner ? __('%Friends%') : __(":name's %friends%", ['name' => $owner->name]);
    // No unlink action here: OpenPNE 3's list is a pure photo table, and unlinking lives on
    // friend/manage, where the roster carries its delete column.
    $items = $friends->map(fn ($friend) => [
        'url' => route('member.profile.show', $friend),
        'file' => $friend->avatar?->file,
        'name' => $friend->name,
        'count' => $friend->friendships_count,
    ])->all();
@endphp

@section('title', $title)

@section('content')
    @if ($friends->isEmpty())
        {{-- OpenPNE 3 listError.php swaps the photo table for a plain box once the pager is empty. --}}
        <x-classic.parts id="noFriend" name="box" :title="$title">
            <div class="body">{{ __('No %friends% to show.') }}</div>
        </x-classic.parts>

        {{-- listError.php closes the empty list with the history-back line; the populated list has
             its pager instead. --}}
        <x-classic.history-back :fallback="route('home')" />
    @else
        <x-classic.parts id="friendList" name="photoTable" :title="$title">
            <x-classic.photo-table :items="$items" :paginator="$friends" />
        </x-classic.parts>
    @endif
@endsection
