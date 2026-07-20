@extends('layouts.classic')

@php
    $searchable = $variant !== 'friends';
    $title = match (true) {
        $variant === 'friends' => __('%Diaries% of %My_friends%'),
        $variant === 'search' && $hasKeyword => __('Search Results'),
        default => __('Recently Posted %Diaries%'),
    };
@endphp

@section('title', $title)

@section('content')
    @if ($searchable)
        <div id="diarySearchFormLine" class="parts searchFormLine">
            <form method="GET" action="{{ route('diary.search') }}">
                <p class="form">
                    <input id="keyword" type="text" class="input_text" name="keyword" size="30" value="{{ $keyword }}">
                    <input type="submit" class="input_submit" value="{{ __('Search') }}">
                </p>
            </form>
        </div>
    @endif

    <div class="dparts" id="diary_feed">
        <div class="partsHeading"><h3>{{ $title }}</h3></div>
        <div class="parts">
            @if ($diaries->isEmpty())
                <p>{{ __('No %diary% entries to show.') }}</p>
            @else
                <ul class="diaryList">
                    @foreach ($diaries as $entry)
                        <li>
                            {{-- OpenPNE 3 listSuccess shows the author's avatar (76×76) linking to the
                                 entry, with the no_image fallback when unset; listFriendSuccess (friends)
                                 omits it entirely. --}}
                            @if ($variant !== 'friends')
                                @php($authorAvatar = $entry->member->avatar?->file)
                                <a class="photo" href="{{ route('diary.show', $entry) }}"><x-classic.image :file="$authorAvatar" :size="76" :alt="$entry->member->name" /></a>
                            @endif
                            <a href="{{ route('diary.show', $entry) }}">{{ \App\Features\Diary\DiaryTitle::withCount($entry) }}</a>
                            {{-- A camera marker when the entry has photos. OpenPNE 4 ships no gif;
                                 the .imageIcon hook lets themes/customers style it. --}}
                            @if ($entry->images_count > 0)<span class="imageIcon" title="{{ __('This entry has photos') }}" aria-label="{{ __('This entry has photos') }}">📷</span>@endif
                            <span class="diaryAuthor">{{ $entry->member->name }}</span>
                            <span class="diaryDate">{{ \App\Support\LocalizedDate::dateTime($entry->created_at) }}</span>
                            {{-- OpenPNE 3 listSuccess shows a body excerpt; listFriendSuccess (friends) does not. --}}
                            @if ($variant !== 'friends')
                                <p class="summary">{{ \App\Support\BodyRenderer::excerpt($entry->body, $entry->format) }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>

                {{ $diaries->links() }}
            @endif
        </div>
    </div>
@endsection
