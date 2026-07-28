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
    @elseif ($variant === 'friends')
        {{-- listFriendSuccess.php renders the recentList skin: a flat list with neither the author
             photo nor the body excerpt the all-member feed carries. --}}
        <x-classic.parts id="diary_feed" name="recentList" :title="$title">
            <ul class="diaryList">
                @foreach ($diaries as $entry)
                    <li>
                        <a href="{{ route('diary.show', $entry) }}">{{ \App\Features\Diary\DiaryTitle::withCount($entry) }}</a>
                        <x-diary.image-icon :count="$entry->images_count" />
                        <span class="diaryAuthor">{{ $entry->member->name }}</span>
                        <span class="diaryDate">{{ \App\Support\LocalizedDate::dateTime($entry->created_at) }}</span>
                    </li>
                @endforeach
            </ul>

            {{ $diaries->links() }}
        </x-classic.parts>
    @else
        {{-- listSuccess.php renders the searchResultList skin but hand-writes the band rather than
             calling _partsSearchResultList.php, and it diverges from that partial: the photo cell
             carries no "Details" link, rowspan is fixed at 4, and the closing tr.operation pairs the
             datetime with the entry link. Kept hand-written here for the same reason — folding these
             differences into <x-classic.search-result-list> would parameterise the shared partial for
             a single caller. --}}
        <x-classic.parts id="diary_feed" name="searchResultList" :title="$title">
            <x-classic.pager :paginator="$diaries" />
            <div class="block">
                @foreach ($diaries as $entry)
                    @php($url = route('diary.show', $entry))
                    <div class="ditem"><div class="item"><table><tbody>
                        <tr>
                            <td rowspan="4" class="photo"><a href="{{ $url }}"><x-classic.image :file="$entry->member->avatar?->file" :size="76" :alt="$entry->member->name" /></a></td>
                            <th>{{ __('%Nickname%') }}</th><td>{{ $entry->member->name }}</td>
                        </tr>
                        <tr>
                            {{-- OpenPNE 3 prints the title unlinked; the photo cell and the
                                 operation row carry the two links to the diary. --}}
                            <th>{{ __('Title') }}</th><td>{{ \App\Features\Diary\DiaryTitle::withCount($entry) }}<x-diary.image-icon :count="$entry->images_count" /></td>
                        </tr>
                        <tr>
                            <th>{{ __('Body') }}</th><td>{{ \App\Support\BodyRenderer::excerpt($entry->body, $entry->format) }}</td>
                        </tr>
                        <tr class="operation">
                            <th>{{ __('Created At') }}</th><td><span class="text">{{ \App\Support\LocalizedDate::dateTime($entry->created_at) }}</span> <span class="moreInfo"><a href="{{ $url }}">{{ __('View this %diary%') }}</a></span></td>
                        </tr>
                    </tbody></table></div></div>
                @endforeach
            </div>
            <x-classic.pager :paginator="$diaries" />
        </x-classic.parts>
    @endif
@endsection
