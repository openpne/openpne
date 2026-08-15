{{-- Marks the member a name belongs to as an AI account. Classic has no chip vocabulary, so the mark
     is the parenthetical the string-only surfaces already use; the class is the custom-CSS seam.
     Renders nothing for a human, so a call site passes the fact rather than guarding on it, and
     brings its own separator — a non-breaking one, so the mark never wraps away from the name it is
     about (a component's output is trimmed, so a plain space could not travel here anyway). --}}
@props(['isAi' => false])
@if ($isAi)&nbsp;<span class="aiMark">({{ __('AI') }})</span>@endif
