{{-- A member-search form (GET, `name` keyword) to member.search. The ul/li structure carries
     the skin's searchFormLine layout. --}}
@props(['config' => [], 'subject' => null, 'partId' => null])
<x-classic.parts :id="$partId" name="searchFormLine">
    <div class="sortHandle">
        <form method="GET" action="{{ route('member.search') }}">
            <ul>
                <li><input type="text" class="input_text" name="name" value=""></li>
                <li><input type="submit" class="input_submit" value="{{ __('Search') }}"></li>
            </ul>
        </form>
    </div>
</x-classic.parts>
