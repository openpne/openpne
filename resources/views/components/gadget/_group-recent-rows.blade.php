{{-- Shared rows for the OpenPNE 3 community recent-list gadgets. The title already carries the
     comment count (op_truncate + "(N)", no separating space); the group name follows the link. --}}
@props(['entries' => []])
<ul class="articleList">
    @foreach ($entries as $entry)
        <li><span class="date">{{ $entry['date'] }}</span><a href="{{ $entry['url'] }}">{{ $entry['title'] }}</a> ({{ $entry['group'] }})</li>
    @endforeach
</ul>
