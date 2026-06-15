{{-- OpenPNE 3 community/joinListBox (nineTable). Skipped entirely when the member has joined no
     community, matching OpenPNE 3's empty-content drop. --}}
@if (count($items))
    <x-gadget-part :part-id="$partId" part-name="nineTable" :title="__('%Community%')">
        <x-gadget.nine-table :items="$items" :rows="$rows" :cols="$cols" :type="$type" />
    </x-gadget-part>
@endif
