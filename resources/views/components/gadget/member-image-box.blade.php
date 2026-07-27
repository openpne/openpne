{{-- The subject member's avatar (p.photo) and name (p.text). --}}
@props(['config' => [], 'subject' => null, 'partId' => null])
@if ($subject)
    @php($avatar = $subject->avatar?->file)
    <x-classic.parts :id="$partId" name="memberImageBox">
        <div class="sortHandle">
            <p class="photo">
                <x-classic.image :file="$avatar" :size="180" :alt="$subject->name" />
            </p>
            <p class="text">{{ $subject->name }}</p>
        </div>
    </x-classic.parts>
@endif
