{{-- The subject member's avatar (p.photo) and name (p.text). --}}
@props(['config' => [], 'subject' => null, 'partId' => null])
@if ($subject)
    @php($avatar = $subject->avatar?->file)
    <x-classic.parts :id="$partId" name="memberImageBox">
        <div class="sortHandle">
            <p class="photo">
                <x-classic.image :file="$avatar" :size="180" :alt="$subject->name" />
            </p>
            <p class="text">{{ $subject->name }}<x-classic.ai-mark :is-ai="$subject->isAiAccount()" /></p>
        </div>
        @if ($subject->is(auth()->user()))
            {{-- OpenPNE 3's parts frame renders its moreInfo option as div.moreInfo > ul.moreInfo.
                 Its other-member entry, "Show more Photos", has no counterpart: a member holds one
                 avatar here, so there is no album page to open. --}}
            <div class="moreInfo">
                <ul class="moreInfo">
                    <li><a href="{{ route('member.avatar.edit') }}">{{ __('Edit Photo') }}</a></li>
                    <li><a href="{{ route('member.profile.mine_compat') }}">{{ __('Show Profile') }}</a></li>
                </ul>
            </div>
        @endif
    </x-classic.parts>
@endif
