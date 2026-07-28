{{-- Skipped entirely when the list is empty, so a friendless member gets no orphan heading. --}}
@if (count($items))
    <x-classic.parts :id="$partId" name="nineTable" :title="__('%Friends%')">
        <x-gadget.nine-table :items="$items" :rows="$rows" :cols="$cols" :type="$type" />
        {{-- OpenPNE 3's parts frame renders its moreInfo option as div.moreInfo > ul.moreInfo. --}}
        <div class="moreInfo">
            <ul class="moreInfo">
                <li><a href="{{ route('friend.list', ['id' => $subject->getKey()]) }}">{{ __('Show all') }}({{ $total }})</a></li>
                @if ($isSelf)
                    {{-- OpenPNE 3's friend/manage (the roster with unlink links) folded into
                         friend/list here; /friend/manage is the pending-request screen and would
                         misdirect this label. --}}
                    <li><a href="{{ route('friend.list') }}">{{ __('Manage %my_friends%') }}</a></li>
                @endif
            </ul>
        </div>
    </x-classic.parts>
@endif
