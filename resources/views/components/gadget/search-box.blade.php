{{-- OpenPNE 3 default/searchBox (searchFormLine single parts): a member-search form (GET, `name`
     keyword) to member.search. The ul/li structure carries the skin's searchFormLine layout. --}}
@props(['config' => [], 'subject' => null, 'partId' => null])
<x-gadget-part :part-id="$partId" part-name="searchFormLine" :single="true">
    <div class="sortHandle">
        <form method="GET" action="{{ route('member.search') }}">
            <ul>
                <li><input type="text" class="input_text" name="name" value=""></li>
                <li><input type="submit" class="input_submit" value="{{ __('Search') }}"></li>
            </ul>
        </form>
    </div>
</x-gadget-part>
