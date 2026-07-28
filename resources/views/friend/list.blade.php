@extends('layouts.classic')

@php
    $isOwner = $owner->is(auth()->user());
    $title = $isOwner ? __('%Friends%') : __(":name's %friends%", ['name' => $owner->name]);
    $items = $friends->map(fn ($friend) => [
        'url' => route('member.profile.show', $friend),
        'file' => $friend->avatar?->file,
        'name' => $friend->name,
        'count' => $friend->friendships_count,
        // OpenPNE 3 unlinks from friend/unlink, reached off the member profile; that entry point is
        // not ported yet, so the per-row link stays bare in the name cell rather than leaving no
        // route to unfriending at all.
        'action' => $isOwner
            ? ['url' => route('friend.unlink.show', $friend), 'label' => __('Remove %friend%')]
            : null,
    ])->all();
@endphp

@section('title', $title)

@section('content')
    @if ($friends->isEmpty())
        {{-- OpenPNE 3 listError.php swaps the photo table for a plain box once the pager is empty. --}}
        <x-classic.parts id="noFriend" name="box" :title="$title">
            <div class="body">{{ __('No %friends% to show.') }}</div>
        </x-classic.parts>
    @else
        <x-classic.parts id="friendList" name="photoTable" :title="$title">
            <x-classic.photo-table :items="$items" :paginator="$friends" />
        </x-classic.parts>
    @endif
@endsection
