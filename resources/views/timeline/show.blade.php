@extends('layouts.classic')

@php($title = __(":name's %activity%", ['name' => $post->member->name]))

@section('title', $title)

@section('content')
    {{-- OpenPNE 3's showSuccess.php emits a bare partsHeading over a .timeline-large div its themes
         alias to the box treatment, so there is no kind to reproduce; the frame keeps the heading
         inside the box and the OpenPNE 4 id stands. --}}
    <x-classic.parts id="timeline_show" :title="$title">
        @if (session('status'))
            <p role="status">{{ session('status') }}</p>
        @endif

        <div class="timeline-post" data-timeline-id="{{ $post->getKey() }}">
            <div class="timeline-member-name">
                <a href="{{ route('member.profile.show', $post->member) }}">{{ $post->member->name }}</a>
            </div>
            <div class="timeline-post-body"><x-user-text :value="$post->body" /></div>
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
                        <div class="timeline-post-body"><x-user-text :value="$reply->body" /></div>
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

        <form method="POST" action="{{ route('timeline.reply.store', $post) }}" class="timeline-reply-form">
            @csrf
            <textarea name="body" maxlength="140" required></textarea>
            @error('body')
                <p role="alert">{{ $message }}</p>
            @enderror
            <button type="submit">{{ __('Reply') }}</button>
        </form>

        <p><a href="{{ route('timeline.member', $post->member) }}">{{ __(":name's %activity%", ['name' => $post->member->name]) }}</a></p>
    </x-classic.parts>
@endsection
