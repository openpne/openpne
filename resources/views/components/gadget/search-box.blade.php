{{-- OpenPNE 3 default/searchBox: a member-search form (GET, `name` keyword) to member.search. --}}
@props(['config' => [], 'subject' => null, 'partId' => null])
<x-gadget-part :part-id="$partId" :title="__('Member search')">
    <form method="GET" action="{{ route('member.search') }}">
        <input type="text" name="name" value="">
        <button type="submit">{{ __('Search') }}</button>
    </form>
</x-gadget-part>
