{{-- Skipped entirely when the member has joined no community, so there is no orphan heading. --}}
@if (count($items))
    <x-classic.parts :id="$partId" name="nineTable" :title="__('%Community%')">
        <x-gadget.nine-table :items="$items" :rows="$rows" :cols="$cols" :type="$type" />
        {{-- OpenPNE 3's parts frame renders its moreInfo option as div.moreInfo > ul.moreInfo. --}}
        <div class="moreInfo">
            <ul class="moreInfo">
                <li><a href="{{ route('community.list_mine', ['id' => $subject->getKey()]) }}">{{ __('Show all') }}({{ $total }})</a></li>
            </ul>
        </div>
    </x-classic.parts>
@endif
