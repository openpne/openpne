{{-- Skipped entirely when the list is empty, so a friendless member gets no orphan heading. --}}
@if (count($items))
    <x-gadget-part :part-id="$partId" part-name="nineTable" :title="__('%Friends%')">
        <x-gadget.nine-table :items="$items" :rows="$rows" :cols="$cols" :type="$type" />
    </x-gadget-part>
@endif
