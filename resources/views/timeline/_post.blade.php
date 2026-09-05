{{-- A single timeline post row, shared by the timeline screens and gadgets. OpenPNE 3 builds this
     DOM client-side (timelineTemplate); the Classic adapter renders the same shape server-side.
     The first control div always renders (OpenPNE 3 emits it empty for a public post); its visibility
     span shows OpenPNE 3's friend/private callouts plus Open, the OpenPNE 4-native audience a
     member should see named. The delete control shows only on the viewer's own posts.

     $thread marks the one row that *is* the thread page: it carries every reply and no way to add
     one, because the page's own reply form below it is that. Everywhere else the row carries the
     tail of the thread, an inline form, and — past the tail — a link to fetch the rest. --}}
@php($thread ??= false)
<div class="timeline-post" data-timeline-id="{{ $post->getKey() }}">
    <a name="timeline-{{ $post->getKey() }}"></a>
    @if ($post->member->is(auth()->user()))
        @include('timeline._delete-dialog', ['post' => $post, 'ajaxDelete' => ! $thread])
    @endif
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
                {{-- OpenPNE 3's lightbox link around the thumbnail; the href is the full-size file. --}}
                <a href="{{ $image->file->url() }}" rel="lightbox"><div><img class="timeline-post-image" src="{{ $image->file->thumbnailUrl(120, 120, square: true) }}" alt=""></div></a>
            @endif
        @endforeach
        <div class="timeline-post-control">
            @if ($post->visibility !== \App\Support\Visibility::Members)
                <span class="public-flag">{{ __('Visibility') }}:{{ __($post->visibility->label()) }}</span>
            @endif
        </div>
        <div class="timeline-post-control">
            {{-- Every control below is a working link or a working form on its own; the script only
                 keeps the reader on the page. The anchor lands on the reply form: viewing a thread
                 is what admits replying to it. --}}
            @php($canPost ??= \App\Features\Timeline\TimelinePosting::enabled())
            @if ($canPost)
                <a class="timeline-comment-link" href="{{ route('timeline.show', $post) }}#timeline-reply-form">{{ __('Post comment') }}</a>
            @endif
            @if ($post->member->is(auth()->user()))
                @if ($canPost) | @endif<a class="timeline-post-delete-confirm-link" href="{{ route('timeline.delete.show', $post) }}" data-dialog="timeline-post-delete-confirm-{{ $post->getKey() }}">{{ __('Delete') }}</a>
            @endif
            @if ($canPost || $post->member->is(auth()->user())) | @endif<a href="{{ route('timeline.show', $post) }}"><x-timeline-timestamp :date="$post->created_at" /></a>

            @if (! $thread && $post->replies_count > \App\Features\Timeline\Queries\RecentReplies::LIMIT)
                <a id="timeline-comment-loadmore-{{ $post->getKey() }}" class="timeline-comment-loadmore" href="{{ route('timeline.show', $post) }}"
                   data-timeline-id="{{ $post->getKey() }}" data-replies-url="{{ route('timeline.replies', $post) }}"><i class="icon-comment"></i>&nbsp;{{ __('See earlier comments') }}<span id="timeline-comment-loader-{{ $post->getKey() }}" class="timeline-comment-loader"><img src="{{ asset('images/ajax-loader.gif') }}" alt=""></span></a>
            @endif

            <div class="timeline-post-comments" id="commentlist-{{ $post->getKey() }}">
                @foreach ($thread ? $post->replies : $post->recentReplies as $reply)
                    @include('timeline._reply', ['reply' => $reply])
                @endforeach
                @if (! $thread && $canPost)
                    <form method="POST" action="{{ route('timeline.reply.store', $post) }}" id="timeline-post-comment-form-{{ $post->getKey() }}" class="timeline-post-comment-form"
                          data-timeline-reply data-error-text="{{ __('Failed to post.') }}">
                        @csrf
                        <input type="text" name="body" class="timeline-post-comment-form-input" id="comment-textarea-{{ $post->getKey() }}" data-timeline-id="{{ $post->getKey() }}" aria-label="{{ __('Post comment') }}">
                        <button type="submit" class="btn btn-primary btn-mini timeline-comment-button">{{ __('Post') }}</button>
                    </form>
                    <div id="timeline-post-comment-form-loader-{{ $post->getKey() }}" class="timeline-post-comment-form-loader" role="status"><img src="{{ asset('images/ajax-loader.gif') }}" alt="{{ __('Sending') }}"></div>
                    <div id="timeline-post-comment-form-error-{{ $post->getKey() }}" class="timeline-post-comment-form-loader" role="alert"></div>
                @endif
            </div>
        </div>
    </div>
</div>
