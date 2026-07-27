@extends('layouts.classic')

@section('title', $diary->title)

@section('sidemenu')
    <x-diary.sidemenu :member="$diary->member" :year="$diary->created_at->year" :month="$diary->created_at->month" />
@endsection

@section('content')
    <x-classic.parts id="diary_show" name="diaryDetailBox">
        {{-- OpenPNE 3 showSuccess.php keeps the diary's effective audience inside partsHeading,
             beside the h3, in the .public hook. --}}
        <x-slot:heading>
            <h3>{{ $diary->title }}</h3>
            <p class="public">({{ __($diary->visibility->label()) }})</p>
        </x-slot:heading>

        @if ($previousDiary || $nextDiary)
            <div class="block prevNextLinkLine">
                @if ($previousDiary)
                    <p class="prev"><a href="{{ route('diary.show', $previousDiary) }}">{{ __('Previous %Diary%') }}</a></p>
                @endif
                @if ($nextDiary)
                    <p class="next"><a href="{{ route('diary.show', $nextDiary) }}">{{ __('Next %Diary%') }}</a></p>
                @endif
            </div>
        @endif
        <p class="diaryMeta">
            {{ $diary->member->name }} &mdash; {{ \App\Support\LocalizedDate::dateTime($diary->created_at) }}
        </p>
        <div class="diaryBody"><x-user-text :value="$diary->body" :format="$diary->format" /></div>

        @include('community-topic._images', ['images' => $diary->images])

        @if ($diary->member->is(auth()->user()))
            <p>
                <a href="{{ route('diary.edit', $diary) }}">{{ __('Edit') }}</a>
                <a href="{{ route('diary.delete.show', $diary) }}">{{ __('Delete') }}</a>
            </p>
        @endif
    </x-classic.parts>

    @if ($thread->total > 0)
        <x-classic.parts id="diary_comment_list" name="commentList" :title="__('Comments')">
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
        </x-classic.parts>
    @endif

    <x-classic.parts id="formDiaryComment" name="form" :title="__('Post a comment')">
        <form method="POST" action="{{ route('diary.comment.store', $diary) }}" enctype="multipart/form-data">
            @csrf
            {{-- OpenPNE 3's form parts renders its `body` option as a .block inside the form tag. --}}
            @if ($diary->visibility === \App\Support\Visibility::Open)
                <div class="block"><p class="attention">{{ __('Your comment is visible to everyone on the web.') }}</p></div>
            @endif
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
    </x-classic.parts>

    {{-- Back to the author's diary list. --}}
    <x-classic.parts id="lineLinkToDiaryMemberList" name="line">
        <a href="{{ route('diary.list_member', $diary->member) }}">{{ __(":name's %diary%", ['name' => $diary->member->name]) }}</a>
    </x-classic.parts>
@endsection
