{{-- OpenPNE 3 parts wrapper (_partsLayout). `partName` is the parts class skins target
     (e.g. nineTable, informationBox); `single` parts drop the inner div.parts. `partId` is the
     kind's OpenPNE 3 DOM id (custom-CSS seam); null when the kind had none. --}}
@props(['partId' => null, 'partName' => null, 'single' => false, 'title' => null])
@php($outerClass = ($single ? 'parts' : 'dparts').($partName ? ' '.$partName : ''))
@if ($single)
    <div class="{{ $outerClass }}"@if ($partId) id="{{ $partId }}"@endif>
        @if ($title !== null && $title !== '')
            <div class="partsHeading"><h3>{{ $title }}</h3></div>
        @endif
        {{ $slot }}
    </div>
@else
    <div class="{{ $outerClass }}"@if ($partId) id="{{ $partId }}"@endif>
        <div class="parts">
            @if ($title !== null && $title !== '')
                <div class="partsHeading"><h3>{{ $title }}</h3></div>
            @endif
            {{ $slot }}
        </div>
    </div>
@endif
