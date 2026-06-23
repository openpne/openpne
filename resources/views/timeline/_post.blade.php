{{-- A single timeline post card, shared by the member timeline and the home feed. OpenPNE 3 builds
     each post client-side from the API (_timelineTemplate.php); the Classic adapter renders it
     server-side. The delete control shows only on the viewer's own posts. --}}
<li class="timeline-post" data-timeline-id="{{ $post->getKey() }}">
    <div class="timeline-member-name">
        {{-- OpenPNE 3 shows the @screen_name handle here; Classic shows the nickname. --}}
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
        @if ($post->member->is(auth()->user()))
            <a href="{{ route('timeline.delete.show', $post) }}">{{ __('Delete') }}</a>
        @endif
    </div>
</li>
