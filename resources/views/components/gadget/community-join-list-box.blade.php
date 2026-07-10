{{-- Skipped entirely when the member has joined no community, so there is no orphan heading. --}}
@if (count($items))
    <x-gadget-part :part-id="$partId" part-name="nineTable" :title="__('%Community%')">
        <x-gadget.nine-table :items="$items" :rows="$rows" :cols="$cols" :type="$type" />
    </x-gadget-part>
@endif
