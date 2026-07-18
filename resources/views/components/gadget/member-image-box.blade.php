{{-- The subject member's avatar (p.photo) and name (p.text). --}}
@props(['config' => [], 'subject' => null, 'partId' => null])
@if ($subject)
    @php($avatar = $subject->avatar?->file)
    <x-gadget-part :part-id="$partId" part-name="memberImageBox" :single="true">
        <div class="sortHandle">
            <p class="photo">
                <x-classic.image :file="$avatar" :size="180" :alt="$subject->name" />
            </p>
            <p class="text">{{ $subject->name }}</p>
        </div>
    </x-gadget-part>
@endif
