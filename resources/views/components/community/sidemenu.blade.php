{{-- The community image box and the member grid (nineTable, admins first). --}}
@props(['community', 'members' => []])

<div class="parts memberImageBox">
    @php($image = $community->image)
    @if ($image)
        <p class="photo"><a href="{{ $image->url() }}" target="_blank" rel="noopener"><img src="{{ $image->thumbnailUrl(120, 120, square: true) }}" alt="{{ $community->name }}"></a></p>
    @endif
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
