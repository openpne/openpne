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
        <x-classic.parts id="diarySearchFormLine" name="searchFormLine">
            <form method="GET" action="{{ route('diary.search') }}">
                <p class="form">
                    <input id="keyword" type="text" class="input_text" name="keyword" size="30" value="{{ $keyword }}">
                    <input type="submit" class="input_submit" value="{{ __('Search') }}">
                </p>
            </form>
        </x-classic.parts>
    @endif

    @if ($diaries->isEmpty())
        {{-- OpenPNE 3 listSuccess.php swaps the result list for a plain box once the pager is empty. --}}
        <x-classic.parts id="diaryList" name="box" :title="$title">
            <div class="body">{{ __('No %diary% entries to show.') }}</div>
        </x-classic.parts>
    @else
        {{-- The two OpenPNE 3 templates disagree on the kind: the all-member feed and search render
             the searchResultList skin (listSuccess.php), the friend feed recentList
             (listFriendSuccess.php). --}}
        <x-classic.parts id="diary_feed" :name="$variant === 'friends' ? 'recentList' : 'searchResultList'" :title="$title">
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
        </x-classic.parts>
    @endif
@endsection
