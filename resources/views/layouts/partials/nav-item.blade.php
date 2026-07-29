{{-- One navigation <li>. $item comes from NavigationService::visibleEntries. A logout-style
     item (GET-unreachable in OpenPNE 4) renders as a POST form button — the POST is the CSRF
     protection OpenPNE 3's bare link lacked — dressed as the plain tab OpenPNE 3 drew, so the
     header reads as one row of tabs. --}}
<li id="{{ $item['domId'] }}">
    @if ($item['isPostLogout'])
        <form method="POST" action="{{ $item['href'] }}">
            @csrf
            <button type="submit">{{ $item['label'] }}</button>
        </form>
    @else
        <a href="{{ $item['href'] }}">{{ $item['label'] }}</a>
    @endif
</li>
