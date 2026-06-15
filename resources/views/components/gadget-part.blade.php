{{-- OpenPNE 3 gadget block markup (dparts > partsHeading > parts). `partId` is the kind's
     OpenPNE 3-compatible DOM id (custom-CSS seam); null when the kind had none. --}}
@props(['partId' => null, 'title' => null])
<div class="dparts"@if ($partId) id="{{ $partId }}"@endif>
    @if ($title !== null && $title !== '')
        <div class="partsHeading"><h3>{{ $title }}</h3></div>
    @endif
    <div class="parts">
        {{ $slot }}
    </div>
</div>
