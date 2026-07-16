{{-- OpenPNE 3 diaryList (_diaryList): the all-members feed, dropped entirely when empty. Rows
     carry the author name (withName). --}}
@if (count($entries))
    <div class="dparts homeRecentList"@if ($partId !== null) id="{{ $partId }}"@endif><div class="parts">
        <div class="partsHeading"><h3>{{ __('Recently Posted %Diaries% of All') }}</h3></div>
        <div class="block">
            @include('components.gadget._diary-article-rows', ['entries' => $entries, 'withName' => true])
            <div class="moreInfo"><ul class="moreInfo">
                <li><a href="{{ route('diary.list') }}">{{ __('More') }}</a></li>
            </ul></div>
        </div>
    </div></div>
@endif
