{{-- OpenPNE 3 message/_sidemenu.php: the box nav (Inbox / Sent / Drafts / Trash). The current box
     is not linked on a list page (OpenPNE 3 forceLink=false) and linked on a show page (forceLink=true). --}}
@props(['current', 'linkCurrent' => false])
<div class="parts pageNav">
    <ul>
        @foreach (\App\Features\Message\MessageBox::cases() as $box)
            <li @class(['current' => $current === $box])>
                @if ($current !== $box || $linkCurrent)
                    <a href="{{ route($box->listRoute()) }}">{{ $box->heading() }}</a>
                @else
                    {{ $box->heading() }}
                @endif
            </li>
        @endforeach
    </ul>
</div>
