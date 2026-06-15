{{-- OpenPNE 3 default/memberImageBox: the subject member's avatar + name, linking to the profile. --}}
@props(['config' => [], 'subject' => null, 'partId' => null])
@if ($subject)
    @php($avatar = $subject->avatar?->file)
    <x-gadget-part :part-id="$partId">
        <div class="memberImageBox">
            <a href="{{ route('member.profile.show', $subject) }}">
                @if ($avatar)
                    <img src="{{ $avatar->thumbnailUrl(120, 120, square: true) }}" alt="{{ $subject->name }}">
                @endif
                <span class="memberName">{{ $subject->name }}</span>
            </a>
        </div>
    </x-gadget-part>
@endif
