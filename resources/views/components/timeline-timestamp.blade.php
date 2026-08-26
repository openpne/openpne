{{-- OpenPNE 3's span.timestamp.timeago: the absolute datetime as the text and the hover title, the
     machine value in data-datetime for classic-timeago.js to count from. OpenPNE 3 put an RFC 2822
     date in the title and read it back; a reader hovering here gets the same words as the row. --}}
@props(['date'])
<span class="timestamp timeago" title="{{ \App\Support\LocalizedDate::dateTime($date) }}" data-datetime="{{ $date->toIso8601String() }}">{{ \App\Support\LocalizedDate::dateTime($date) }}</span>
