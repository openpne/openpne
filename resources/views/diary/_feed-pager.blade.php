{{-- Search is the archive and keeps OpenPNE 3's counted pager; the feeds are streams. --}}
@if ($variant === 'search')
    <x-classic.pager :paginator="$diaries" />
@else
    <x-classic.stream-pager :older-url="$olderUrl" :newer-url="$newerUrl" />
@endif
