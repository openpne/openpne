{{-- Shared diary rows for the OpenPNE 3 diary list gadgets. `withName` appends the author
     (OpenPNE 3 op_diary_link_to_show withName); `withIcon` shows the camera marker for a diary
     with photos (images_count > 0). --}}
@props(['entries' => [], 'withName' => false, 'withIcon' => true])
<ul class="articleList">
    @foreach ($entries as $entry)
        <li><span class="date">{{ $entry['date'] }}</span><a href="{{ $entry['url'] }}">{{ $entry['title'] }}</a>@if ($withName) ({{ $entry['author'] }})@endif@if ($withIcon && $entry['hasImages']) <img src="{{ asset('images/icon_camera.gif') }}" alt="photo">@endif</li>
    @endforeach
</ul>
