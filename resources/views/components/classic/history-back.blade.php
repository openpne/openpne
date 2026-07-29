{{-- OpenPNE 3's backLink line: the "Back to previous page" step it closed empty lists with
     (`link_to_function(..., 'history.back()')`). The href is a real destination, so the control
     works before the script does; the script prefers the browser's own history when there is any.
     One deferred handler serves every instance on the page. --}}
@props(['fallback'])
<x-classic.parts id="backLink" name="line">
    <a href="{{ $fallback }}" data-history-back>{{ __('Back to previous page') }}</a>
</x-classic.parts>
@once
    <script src="{{ asset('js/classic-history-back.js') }}" defer></script>
@endonce
