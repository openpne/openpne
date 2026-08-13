{{-- The community image box and the member grid (nineTable, admins first). --}}
@props(['group', 'members' => [], 'canManageMembers' => false])

<x-classic.parts id="communityImageBox" name="memberImageBox">
    @php($image = $group->image)
    <div class="sortHandle">
        <p class="photo">
            @if ($image)
                {{-- The link opens the full-size image; the no_image fallback has none, so it renders bare. --}}
                <a href="{{ $image->url() }}" target="_blank" rel="noopener"><x-classic.image :file="$image" :size="180" :alt="$group->name" /></a>
            @else
                <x-classic.image :file="null" :size="180" :alt="$group->name" />
            @endif
        </p>
        {{-- OpenPNE 3 getNameAndCount(): the caption carries the member count. --}}
        <p class="text">{{ $group->name }} ({{ $group->members_count }})</p>
    </div>
</x-classic.parts>

@php($items = collect($members)->map(fn ($membership) => [
    'url' => route('member.profile.show', $membership->member),
    'imageUrl' => $membership->member->avatar?->file?->thumbnailUrl(76, 76, square: true),
    'name' => $membership->member->name,
    'crown' => $membership->role === \App\Features\Group\GroupRole::Admin,
])->all())
@if (count($items))
    {{-- friendList is what OpenPNE 3 named the *community member* grid: a copy-paste from the
         member sidemenu it never fixed, and skins target it, so it is restored verbatim. --}}
    <x-classic.parts id="friendList" name="nineTable" :title="__('%community% Members')">
        <x-gadget.nine-table :items="$items" />
        {{-- OpenPNE 3's parts frame renders its moreInfo option as div.moreInfo > ul.moreInfo. --}}
        <div class="moreInfo">
            <ul class="moreInfo">
                <li><a href="{{ route('group.members', ['group' => $group->getKey()]) }}">{{ __('Show all') }}({{ $group->members_count }})</a></li>
                @if ($canManageMembers)
                    <li><a href="{{ route('group.members.manage', $group) }}">{{ __('Management member') }}</a></li>
                @endif
            </ul>
        </div>
    </x-classic.parts>
@endif
