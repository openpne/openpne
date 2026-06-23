@extends('layouts.classic')

@php($title = __(":name's %activity%", ['name' => $post->member->name]))

@section('title', $title)

@section('content')
    {{-- OpenPNE 3 showSuccess.php: a post and its reply thread. --}}
    <div class="dparts" id="timeline_show">
        <div class="partsHeading"><h3>{{ $title }}</h3></div>
        <div class="parts">
            @if (session('status'))
                <p role="status">{{ session('status') }}</p>
            @endif

            <div class="timeline-post" data-timeline-id="{{ $post->getKey() }}">
                <div class="timeline-member-name">
                    <a href="{{ route('member.profile.show', $post->member) }}">{{ $post->member->name }}</a>
                </div>
                <div class="timeline-post-body">{{ $post->body }}</div>
                @foreach ($post->images as $image)
                    @if ($image->file)
                        <img class="timeline-post-image" src="{{ $image->file->thumbnailUrl(120, 120, square: true) }}" alt="">
                    @endif
                @endforeach
                <div class="timeline-post-control">
                    <span class="public-flag">{{ __($post->visibility->label()) }}</span>
                    <span class="timestamp">{{ \App\Support\LocalizedDate::dateTime($post->created_at) }}</span>
                    @if ($post->member->is($viewer))
                        <a href="{{ route('timeline.delete.show', $post) }}">{{ __('Delete') }}</a>
                    @endif
                </div>
            </div>

            {{-- Replies, oldest first (OpenPNE 3 reads by id). --}}
            @if ($post->replies->isNotEmpty())
                <ul class="timeline-comment-list">
                    @foreach ($post->replies as $reply)
                        <li class="timeline-comment" data-timeline-id="{{ $reply->getKey() }}">
                            <div class="timeline-member-name">
                                <a href="{{ route('member.profile.show', $reply->member) }}">{{ $reply->member->name }}</a>
                            </div>
                            <div class="timeline-post-body">{{ $reply->body }}</div>
                            <div class="timeline-post-control">
                                <span class="timestamp">{{ \App\Support\LocalizedDate::dateTime($reply->created_at) }}</span>
                                @if ($reply->member->is($viewer))
                                    <a href="{{ route('timeline.delete.show', $reply) }}">{{ __('Delete') }}</a>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif

            {{-- OpenPNE 3 reply form (#timeline-post-comment-form). --}}
            <form method="POST" action="{{ route('timeline.reply.store', $post) }}" class="timeline-reply-form">
                @csrf
                <textarea name="body" maxlength="140" required></textarea>
                @error('body')
                    <p role="alert">{{ $message }}</p>
                @enderror
                <button type="submit">{{ __('Reply') }}</button>
            </form>

            <p><a href="{{ route('timeline.member', $post->member) }}">{{ __(":name's %activity%", ['name' => $post->member->name]) }}</a></p>
        </div>
    </div>
@endsection
