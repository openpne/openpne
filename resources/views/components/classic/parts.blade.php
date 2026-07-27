{{-- OpenPNE 3 `_partsLayout.php`: the frame every Classic parts box shares. `name` is the kind
     class skins target (e.g. nineTable, informationBox); a `single` kind drops the inner div.parts,
     and the kinds whose OpenPNE 3 body partial forced it default to true. `id` is the kind's DOM id
     (custom-CSS seam); null when the kind has none. The body structure (.body, .block, a bare
     table) stays with the caller, as it did with OpenPNE 3's per-kind body partial: .body is not
     universal (listBox / alertBox put a table under .parts; form puts form > table there). --}}
@props(['id' => null, 'name' => null, 'single' => null, 'title' => null])
@php($single ??= in_array($name, ['informationBox', 'line', 'memberImageBox', 'searchFormLine'], true))
@php($outerClass = ($single ? 'parts' : 'dparts').($name ? ' '.$name : ''))
@if ($single)
    <div class="{{ $outerClass }}"@if ($id) id="{{ $id }}"@endif>
        @if (isset($heading))
            <div class="partsHeading">{{ $heading }}</div>
        @elseif ($title !== null && $title !== '')
            <div class="partsHeading"><h3>{{ $title }}</h3></div>
        @endif
        {{ $slot }}
    </div>
@else
    <div class="{{ $outerClass }}"@if ($id) id="{{ $id }}"@endif>
        <div class="parts">
            @if (isset($heading))
                <div class="partsHeading">{{ $heading }}</div>
            @elseif ($title !== null && $title !== '')
                <div class="partsHeading"><h3>{{ $title }}</h3></div>
            @endif
            {{ $slot }}
        </div>
    </div>
@endif
