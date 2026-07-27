@extends('layouts.classic')

@section('title', __('Delete the comment'))

@section('content')
    <x-classic.parts id="deleteConfirmForm" name="form" :title="__('Delete the comment')">
        <form method="POST" action="{{ route('communityTopic.comment.delete', $comment) }}">
            @csrf
            {{-- OpenPNE 3 passes no body option here (no .block); question + preview are OpenPNE 4 additions. --}}
            <p>{{ __('Do you really want to delete this comment?') }}</p>
            <blockquote class="commentPreview">{{ $comment->body }}</blockquote>
            <div class="operation">
                <ul class="moreInfo button">
                    <li><input type="submit" class="input_submit" value="{{ __('Delete') }}"></li>
                    <li><a href="{{ route('communityTopic.show', $comment->topic) }}">{{ __('Back') }}</a></li>
                </ul>
            </div>
        </form>
    </x-classic.parts>
@endsection
