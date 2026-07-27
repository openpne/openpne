@extends('layouts.classic')

@section('title', __('Delete the comment'))

@section('content')
    <x-classic.parts id="diary_comment_delete" name="box" :title="__('Delete the comment')">
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
    </x-classic.parts>
@endsection
