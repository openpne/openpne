{{-- OpenPNE 3 member/_birthdayBox: a bare single-layer greeting image, no dparts wrapper or id. --}}
@if ($image !== null)
    {{-- OpenPNE 3 omitted the alt on the run-up image; empty alt keeps it silent without the axe finding. --}}
    <div class="parts birthday"><img src="{{ $image }}" alt="{{ $alt ?? '' }}"></div>
@endif
