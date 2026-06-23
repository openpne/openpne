@extends('layouts.classic')

@section('title', $diary->title)

@section('sidemenu')
    <x-diary.sidemenu :member="$diary->member" :year="$diary->created_at->year" :month="$diary->created_at->month" />
@endsection

@section('content')
    <div class="dparts" id="diary_show">
        <div class="partsHeading">
            <h3>{{ $diary->title }}</h3>
            {{-- OpenPNE 3 showSuccess.php: the diary's effective audience, in the .public hook. --}}
            <p class="public">({{ __($diary->visibility->label()) }})</p>
        </div>
        <div class="parts">
            {{-- OpenPNE 3 showSuccess.php: links to the author's adjacent diaries the viewer may see. --}}
            @if ($previousDiary || $nextDiary)
                <div class="block prevNextLinkLine">
                    @if ($previousDiary)
                        <p class="prev"><a href="{{ route('diary.show', $previousDiary) }}">{{ __('Previous Diary') }}</a></p>
                    @endif
                    @if ($nextDiary)
                        <p class="next"><a href="{{ route('diary.show', $nextDiary) }}">{{ __('Next Diary') }}</a></p>
                    @endif
                </div>
            @endif
            <p class="diaryMeta">
                {{ $diary->member->name }} &mdash; {{ \App\Support\LocalizedDate::dateTime($diary->created_at) }}
            </p>
            <div class="diaryBody"><x-user-text :value="$diary->body" /></div>

            {{-- OpenPNE 3 showSuccess.php: the diary's attached images as a thumbnail gallery. --}}
            @include('community-topic._images', ['images' => $diary->images])

            @if ($diary->member->is(auth()->user()))
                <p>
                    <a href="{{ route('diary.edit', $diary) }}">{{ __('Edit') }}</a>
                    <a href="{{ route('diary.delete.show', $diary) }}">{{ __('Delete') }}</a>
                </p>
            @endif
        </div>
    </div>

    @if ($thread->total > 0)
        <div class="dparts commentList" id="diary_comment_list">
            <div class="partsHeading"><h3>{{ __('Comments') }}</h3></div>
            <div class="parts">
                {{-- OpenPNE 3 diaryComment/_list.php: page-size switch + order toggle. --}}
                @if ($thread->offersSizeSwitch())
                    <div class="pagerRelative">
                        @foreach ($thread->otherSizes() as $n)
                            <a href="{{ $thread->link(1, $n, $thread->ascending) }}">{{ __('View :count per page', ['count' => $n]) }}</a>
                        @endforeach
                        @if ($thread->hasPages())
                            @if ($thread->ascending)
                                <a href="{{ $thread->link(1, $thread->size, false) }}">{{ __('View Latest') }}</a>
                            @else
                                <a href="{{ $thread->link(1, $thread->size, true) }}">{{ __('View Oldest First') }}</a>
                            @endif
                        @endif
                    </div>
                @endif

                {{-- Older/Newer follow comment age, so they read the same in either order. --}}
                @if ($thread->hasPages())
                    <div class="pagerRelative">
                        @if ($thread->hasOlder())
                            <p class="prev"><a href="{{ $thread->link($thread->olderPage(), $thread->size, $thread->ascending) }}">{{ __('Older') }}</a></p>
                        @endif
                        <p class="number">{{ __('No. :first - :last', ['first' => $thread->firstNumber(), 'last' => $thread->lastNumber()]) }}</p>
                        @if ($thread->hasNewer())
                            <p class="next"><a href="{{ $thread->link($thread->newerPage(), $thread->size, $thread->ascending) }}">{{ __('Newer') }}</a></p>
                        @endif
                    </div>
                @endif

                @foreach ($thread->comments as $comment)
                    <dl>
                        <dt>{{ \App\Support\LocalizedDate::dateTime($comment->created_at) }}</dt>
                        <dd>
                            <div class="title">
                                <p class="heading">
                                    <strong>{{ $comment->number }}</strong>:
                                    @if ($comment->member)
                                        <a href="{{ route('member.profile.show', $comment->member) }}">{{ $comment->member->name }}</a>
                                    @else
                                        {{ __('Withdrawn member') }}
                                    @endif
                                    @if ($comment->isDeletableBy(auth()->user()))
                                        <a href="{{ route('diary.comment.delete.show', $comment) }}">{{ __('Delete') }}</a>
                                    @endif
                                </p>
                            </div>
                            <div class="body"><p class="text"><x-user-text :value="$comment->body" /></p></div>
                            @include('community-topic._images', ['images' => $comment->images])
                        </dd>
                    </dl>
                @endforeach
            </div>
        </div>
    @endif

    <div class="dparts form" id="diary_comment_form">
        <div class="partsHeading"><h3>{{ __('Post a comment') }}</h3></div>
        <div class="parts">
            @if ($diary->visibility === \App\Support\Visibility::Open)
                <p class="attention">{{ __('Your comment is visible to everyone on the web.') }}</p>
            @endif
            <form method="POST" action="{{ route('diary.comment.store', $diary) }}" enctype="multipart/form-data">
                @csrf
                <table>
                    <tr>
                        <th><label for="comment_body">{{ __('Comment') }}</label></th>
                        <td>
                            <textarea id="comment_body" name="body" rows="8" required>{{ old('body') }}</textarea>
                            @error('body')<p class="error">{{ $message }}</p>@enderror
                        </td>
                    </tr>
                    @include('community-topic._image_fields')
                </table>
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('Save') }}"></li>
                    </ul>
                </div>
            </form>
        </div>
    </div>

    {{-- OpenPNE 3 showSuccess.php: lineLinkToDiaryMemberList — back to the author's diary list. --}}
    <div class="line" id="lineLinkToDiaryMemberList">
        <a href="{{ route('diary.list_member', $diary->member) }}">{{ __(":name's %diary%", ['name' => $diary->member->name]) }}</a>
    </div>
@endsection
