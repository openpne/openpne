@extends('layouts.classic')

@section('title', __(':name members', ['name' => $group->name]))

@section('content')
    @php
        $items = $members->map(fn ($membership) => [
            'url' => route('member.profile.show', $membership->member),
            'file' => $membership->member->avatar?->file,
            'name' => $membership->member->name,
            'count' => \App\Support\Feature::Friend->enabled() ? $membership->member->friendships_count : null,
            // OpenPNE 3 crowns the community admin only; sub-admins carry no marker here.
            'crown' => $membership->role === \App\Features\Group\GroupRole::Admin,
        ])->all();
    @endphp

    <x-classic.parts id="communityMembersList" name="photoTable" :title="__(':name members', ['name' => $group->name])">
        <x-classic.photo-table :items="$items" :paginator="$members" />
    </x-classic.parts>
@endsection
