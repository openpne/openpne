{{-- One reply row (OpenPNE 3 timelineCommentTemplate). Also rendered on its own — by the replies
     fragment and by the JSON a posted reply answers with — so it reads nothing but $reply and who
     is looking, and pushes no @once asset of its own. --}}
<div class="timeline-post-comment" data-timeline-id="{{ $reply->getKey() }}">
    <div class="timeline-post-comment-member-image">
        <a href="{{ route('member.profile.show', $reply->member) }}" title="{{ $reply->member->name }}"><x-classic.image :file="$reply->member->avatar?->file" :size="48" :display="36" :alt="$reply->member->name" /></a>
    </div>
    <div class="timeline-post-comment-content">
        <div class="timeline-post-comment-name-and-body">
            <a class="screen-name" href="{{ route('member.profile.show', $reply->member) }}">{{ $reply->member->name }}</a>
            <span class="timeline-post-comment-body"><x-timeline-body :post="$reply" /></span>
            <x-link-card :record="$reply" />
        </div>
        <div class="timeline-post-comment-control">
            @if ($reply->member->is(auth()->user()))
                <a class="timeline-post-delete-confirm-link" href="{{ route('timeline.delete.show', $reply) }}">{{ __('Delete') }}</a> |
            @endif
            <span class="timestamp">{{ \App\Support\LocalizedDate::dateTime($reply->created_at) }}</span>
        </div>
    </div>
</div>
