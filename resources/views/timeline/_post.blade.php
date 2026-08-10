{{-- A single timeline post row, shared by the timeline screens and gadgets. OpenPNE 3 builds this
     DOM client-side (timelineTemplate); the Classic adapter renders the same shape server-side.
     The comment link jumps to the thread page's reply form, where OpenPNE 4 replies live. The
     first control div always renders (OpenPNE 3 emits it empty for a public post); its visibility
     span shows OpenPNE 3's friend/private callouts plus Open, the OpenPNE 4-native audience a
     member should see named. The delete control shows only on the viewer's own posts. --}}
<div class="timeline-post" data-timeline-id="{{ $post->getKey() }}">
    <a name="timeline-{{ $post->getKey() }}"></a>
    <div class="timeline-post-member-image">
        <a href="{{ route('member.profile.show', $post->member) }}" title="{{ $post->member->name }}"><x-classic.image :file="$post->member->avatar?->file" :size="48" :alt="$post->member->name" /></a>
    </div>
    <div class="timeline-post-content">
        <div class="timeline-member-name">
            <a class="screen-name" href="{{ route('member.profile.show', $post->member) }}">{{ $post->member->name }}</a>
        </div>
        <div class="timeline-post-body" id="timeline-post-body-{{ $post->getKey() }}"><x-timeline-body :post="$post" /></div>
        <x-link-card :record="$post" />
        @foreach ($post->images as $image)
            @if ($image->file)
                <img class="timeline-post-image" src="{{ $image->file->thumbnailUrl(120, 120, square: true) }}" alt="">
            @endif
        @endforeach
        <div class="timeline-post-control">
            @if ($post->visibility !== \App\Support\Visibility::Members)
                <span class="public-flag">{{ __('Visibility') }}:{{ __($post->visibility->label()) }}</span>
            @endif
        </div>
        <div class="timeline-post-control">
            <a href="{{ route('timeline.show', $post) }}#timeline-reply-form">{{ __('Post comment') }}</a>
            @if ($post->member->is(auth()->user()))
                | <a href="{{ route('timeline.delete.show', $post) }}">{{ __('Delete') }}</a>
            @endif
            | <a href="{{ route('timeline.show', $post) }}"><span class="timestamp">{{ \App\Support\LocalizedDate::dateTime($post->created_at) }}</span></a>
        </div>
    </div>
</div>
