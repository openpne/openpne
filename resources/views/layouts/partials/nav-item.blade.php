{{-- One navigation <li>. $item comes from NavigationService::visibleEntries. A logout-style
     item (GET-unreachable in OpenPNE 4) renders as a POST form button, as OpenPNE 3 did. --}}
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
