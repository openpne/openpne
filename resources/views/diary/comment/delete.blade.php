@extends('layouts.classic')

@section('title', __('Delete the comment'))

@section('content')
    <div class="dparts box" id="diary_comment_delete">
        <div class="partsHeading"><h3>{{ __('Delete the comment') }}</h3></div>
        <div class="parts">
            <div class="block">
                <p>{{ __('Do you really want to delete this comment?') }}</p>
                <blockquote class="commentPreview">{{ $comment->body }}</blockquote>
                <form method="POST" action="{{ route('diary.comment.delete', $comment) }}">
                    @csrf
                    <div class="operation">
                        <ul class="moreInfo button">
                            <li><input type="submit" class="input_submit" value="{{ __('Delete') }}"></li>
                        </ul>
                    </div>
                </form>
                <p><a href="{{ route('diary.show', $comment->diary) }}">{{ __('Back') }}</a></p>
            </div>
        </div>
    </div>
@endsection
