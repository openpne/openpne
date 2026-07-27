{{-- Skipped entirely when the list is empty, so a friendless member gets no orphan heading. --}}
@if (count($items))
    <x-classic.parts :id="$partId" name="nineTable" :title="__('%Friends%')">
        <x-gadget.nine-table :items="$items" :rows="$rows" :cols="$cols" :type="$type" />
    </x-classic.parts>
@endif
