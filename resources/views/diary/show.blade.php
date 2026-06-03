@extends('layouts.classic')

@section('title', $diary->title)

@section('content')
    <div class="dparts" id="diary_show">
        <div class="partsHeading"><h3>{{ $diary->title }}</h3></div>
        <div class="parts">
            <p class="diaryMeta">
                {{ $diary->member->name }} &mdash; {{ $diary->created_at->format('Y-m-d H:i') }}
            </p>
            <div class="diaryBody">{{ $diary->body }}</div>

            @if ($diary->member->is(auth()->user()))
                <p>
                    <a href="{{ route('diary.edit', $diary) }}">{{ __('Edit') }}</a>
                    <a href="{{ route('diary.delete.show', $diary) }}">{{ __('Delete') }}</a>
                </p>
            @endif
        </div>
    </div>

    @if ($comments->isNotEmpty())
        <div class="dparts commentList" id="diary_comment_list">
            <div class="partsHeading"><h3>{{ __('Comments') }}</h3></div>
            <div class="parts">
                @foreach ($comments as $comment)
                    <dl>
                        <dt>{{ $comment->created_at->format('Y-m-d H:i') }}</dt>
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
                            <div class="body"><p class="text">{{ $comment->body }}</p></div>
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
            <form method="POST" action="{{ route('diary.comment.store', $diary) }}">
                @csrf
                <table>
                    <tr>
                        <th><label for="comment_body">{{ __('Comment') }}</label></th>
                        <td>
                            <textarea id="comment_body" name="body" rows="8" required>{{ old('body') }}</textarea>
                            @error('body')<p class="error">{{ $message }}</p>@enderror
                        </td>
                    </tr>
                </table>
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('Save') }}"></li>
                    </ul>
                </div>
            </form>
        </div>
    </div>
@endsection
