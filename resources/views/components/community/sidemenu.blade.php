{{-- The community image box and the member grid (nineTable, admins first). --}}
@props(['community', 'members' => []])

<div class="parts memberImageBox">
    @php($image = $community->image)
    <p class="photo">
        @if ($image)
            {{-- The link opens the full-size image; the no_image fallback has none, so it renders bare. --}}
            <a href="{{ $image->url() }}" target="_blank" rel="noopener"><x-classic.image :file="$image" :size="120" :alt="$community->name" /></a>
        @else
            <x-classic.image :file="null" :size="120" :alt="$community->name" />
        @endif
    </p>
    <p class="text">{{ $community->name }}</p>
</div>

@php($items = collect($members)->map(fn ($membership) => [
    'url' => route('member.profile.show', $membership->member),
    'imageUrl' => $membership->member->avatar?->file?->thumbnailUrl(76, 76, square: true),
    'name' => $membership->member->name,
])->all())
@if (count($items))
    <div class="dparts nineTable" id="communityMembers">
        <div class="parts">
            <div class="partsHeading"><h3>{{ __('%community% Members') }}</h3></div>
            <x-gadget.nine-table :items="$items" />
            <div class="moreInfo">
                <ul>
                    <li><a href="{{ route('community.members', ['id' => $community->getKey()]) }}">{{ __('Show all') }} ({{ $community->members_count }})</a></li>
                </ul>
            </div>
        </div>
    </div>
@endif
