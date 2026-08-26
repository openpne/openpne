{{-- The Classic timeline's resident scripts, included by the screens and the gadget wrappers — never
     by a row or a fragment. insertAdjacentHTML does not run a script, so one that shipped inside a
     fragment would never start; and the fragment re-renders @once from scratch, so it would ship a
     copy with every insertion. Loaded whatever the first rows hold, because later ones arrive from
     the server. --}}
@once
    <script src="{{ asset('js/classic-timeline-replies.js') }}" defer></script>
@endonce
