{{-- One talk message in OpenPNE 3's timelineTemplate shape (timeline/_post.blade.php for the
     timeline's own rows). No visibility span: a message carries no audience. The reply line is the
     lineage OpenPNE 3's comments became; the conversation itself stays linear. --}}
@php($author = $message->author)
@php($canDelete = $permissions->canDelete($message))
<div class="timeline-post" data-timeline-id="{{ $message->getKey() }}">
    <a name="timeline-{{ $message->getKey() }}"></a>
    @if ($canDelete)
        @include('group-talk._delete-dialog', ['message' => $message])
    @endif
    <div class="timeline-post-member-image">
        @if ($author)
            <a href="{{ route('member.profile.show', $author) }}" title="{{ $author->name }}"><x-classic.image :file="$author->avatar?->file" :size="48" :alt="$author->name" /></a>
        @else
            <x-classic.image :file="null" :size="48" :alt="__('%Withdrawn_member%')" />
        @endif
    </div>
    <div class="timeline-post-content">
        <div class="timeline-member-name">
            @if ($author)
                <a class="screen-name" href="{{ route('member.profile.show', $author) }}">{{ $author->name }}</a>
            @else
                <span class="screen-name">{{ __('%Withdrawn_member%') }}</span>
            @endif
        </div>
        <div class="timeline-post-body" id="timeline-post-body-{{ $message->getKey() }}"><x-group-talk-body :message="$message" /></div>
        <x-link-card :record="$message" />
        @foreach (\App\Files\PostAttachments::partition($message->images)['images'] as $image)
            @if ($image->file)
                <a href="{{ $image->file->url() }}" rel="lightbox" aria-label="{{ __('Image') }}"><div><img class="timeline-post-image" src="{{ $image->file->thumbnailUrl(120, 120, square: true) }}" alt=""></div></a>
            @endif
        @endforeach
        @include('group-topic._files', ['images' => $message->images])
        @if ($message->in_reply_to_id !== null)
            @php($parent = $parents[(int) $message->in_reply_to_id] ?? null)
            <div class="timeline-post-control timeline-post-reply-to">
                @if ($parent)
                    &gt; <a href="{{ route('group.talk.show', ['group' => $message->group_id, 'm' => $parent->getKey()]) }}#timeline-{{ $parent->getKey() }}">{{ $parent->author?->name ?? __('%Withdrawn_member%') }}: {{ \Illuminate\Support\Str::limit($parent->body, 40) }}</a>
                @else
                    &gt; {{ __('Deleted message') }}
                @endif
            </div>
        @endif
        <div class="timeline-post-control">
            @if ($permissions->canPost)
                <a class="timeline-comment-link" href="{{ route('group.talk.show', ['group' => $message->group_id, 'reply' => $message->getKey()]) }}#timeline-textarea">{{ __('Post comment') }}</a>
            @endif
            @if ($canDelete)
                @if ($permissions->canPost) | @endif<a class="timeline-post-delete-confirm-link" href="{{ route('group.talk.delete.show', ['group' => $message->group_id, 'message' => $message]) }}" data-dialog="timeline-post-delete-confirm-{{ $message->getKey() }}">{{ __('Delete') }}</a>
            @endif
            @if ($permissions->canPost || $canDelete) | @endif<a href="{{ route('group.talk.show', ['group' => $message->group_id, 'm' => $message->getKey()]) }}#timeline-{{ $message->getKey() }}"><x-timeline-timestamp :date="$message->created_at" /></a>
        </div>
    </div>
</div>
