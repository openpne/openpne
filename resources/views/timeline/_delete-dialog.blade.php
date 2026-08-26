{{-- OpenPNE 3's inline delete confirmation (timelineTemplate's timeline-post-delete-confirm block, which
     colorbox opened); here a <dialog> classic-timeline-dialogs.js opens, and the confirm page stays
     the path without it. `ajaxDelete` says whether the confirmed form posts as JSON and the row leaves
     the page, or posts as the page would: the thread root on its own page goes the second way,
     because the page itself is what goes. --}}
{{-- aria-label rather than aria-labelledby: the ids repeat when two gadgets draw the same row. --}}
<dialog class="timeline-post-delete-dialog" id="timeline-post-delete-confirm-{{ $post->getKey() }}" aria-label="{{ __('Delete post') }}">
    <div class="timeline-post-delete-confirm">
        <div class="partsHeading"><h3>{{ __('Delete post') }}</h3></div>
        <div class="timeline-post-delete-confirm-context">{{ __('Delete this post?') }}</div>
        <div class="timeline-post-delete-confirm-content">
            <div class="timeline-post-member-image"><x-classic.image :file="$post->member->avatar?->file" :size="48" :alt="$post->member->name" /></div>
            <div class="timeline-post-content">
                <div class="timeline-member-name"><span class="screen-name">{{ $post->member->name }}</span></div>
                <div class="timeline-post-body"><x-timeline-body :post="$post" /></div>
            </div>
            <div class="timeline-post-delete">
                <form method="POST" action="{{ route('timeline.delete', $post) }}"@if ($ajaxDelete) data-timeline-delete data-error-text="{{ __('Failed to delete.') }}"@endif>
                    @csrf
                    <button type="submit" class="timeline-post-delete-button btn btn-danger">{{ __('Delete') }}</button>
                </form>
                <form method="dialog"><button type="submit" class="btn" autofocus>{{ __('Cancel') }}</button></form>
            </div>
            <div class="timeline-post-delete-loading" role="status" hidden><img src="{{ asset('images/ajax-loader.gif') }}" alt="{{ __('Sending') }}"></div>
            <div class="timeline-post-delete-error" role="alert"></div>
        </div>
    </div>
</dialog>
