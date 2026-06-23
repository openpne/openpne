@extends('layouts.classic')

@php($title = __(":name's %activity%", ['name' => $post->member->name]))

@section('title', $title)

@section('content')
    {{-- OpenPNE 3 showSuccess.php: a single timeline post. --}}
    <div class="dparts" id="timeline_show">
        <div class="partsHeading"><h3>{{ $title }}</h3></div>
        <div class="parts">
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
                </div>
            </div>

            <p><a href="{{ route('timeline.member', $post->member) }}">{{ __(":name's %activity%", ['name' => $post->member->name]) }}</a></p>
        </div>
    </div>
@endsection
