{{-- OpenPNE 3 default/memberImageBox (single parts): the subject member's avatar (p.photo) and
     name (p.text). --}}
@props(['config' => [], 'subject' => null, 'partId' => null])
@if ($subject)
    @php($avatar = $subject->avatar?->file)
    <x-gadget-part :part-id="$partId" part-name="memberImageBox" :single="true">
        <div class="sortHandle">
            <p class="photo">
                @if ($avatar)
                    <img src="{{ $avatar->thumbnailUrl(180, 180, square: true) }}" alt="{{ $subject->name }}">
                @endif
            </p>
            <p class="text">{{ $subject->name }}</p>
        </div>
    </x-gadget-part>
@endif
