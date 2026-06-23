@extends('layouts.classic')

@php($title = $owner->is(auth()->user()) ? __('%Activity%') : __(":name's %activity%", ['name' => $owner->name]))

@section('title', $title)

@section('content')
    {{-- OpenPNE 3 timelineProfile component (memberSuccess.php). OpenPNE 3 streams the posts
         client-side from the API; the Classic adapter renders them server-side with a pager. --}}
    <div class="dparts profileTimeline" id="profileTimeline_{{ $owner->getKey() }}">
        <div class="partsHeading"><h3>{{ $title }}</h3></div>
        <div class="parts">
            @if ($posts->isEmpty())
                <p>{{ __('No %activity% posts to show.') }}</p>
            @else
                <ul class="timeline-list">
                    @foreach ($posts as $post)
                        <li class="timeline-post" data-timeline-id="{{ $post->getKey() }}">
                            <div class="timeline-member-name">
                                {{-- screen_name (@handle) lands in Phase B; Classic shows the nickname. --}}
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
                                <a href="{{ route('timeline.show', $post) }}"><span class="timestamp">{{ \App\Support\LocalizedDate::dateTime($post->created_at) }}</span></a>
                            </div>
                        </li>
                    @endforeach
                </ul>

                {{ $posts->links() }}
            @endif
        </div>
    </div>
@endsection
