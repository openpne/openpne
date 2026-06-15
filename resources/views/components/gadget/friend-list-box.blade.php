{{-- OpenPNE 3 friend/friendListBox (nineTable). OpenPNE 3 skips the whole box when the list is
     empty (op_include_parts drops empty content), so a friendless member gets no orphan heading. --}}
@if (count($items))
    <x-gadget-part :part-id="$partId" part-name="nineTable" :title="__('%Friends%')">
        <x-gadget.nine-table :items="$items" :rows="$rows" :cols="$cols" :type="$type" />
    </x-gadget-part>
@endif
