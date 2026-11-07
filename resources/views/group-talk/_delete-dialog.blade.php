{{-- timeline/_delete-dialog.blade.php for a talk message: the <dialog> classic-timeline-dialogs.js
     opens, with the confirm page as the path without it. --}}
@php($author = $message->author)
<dialog class="timeline-post-delete-dialog" id="timeline-post-delete-confirm-{{ $message->getKey() }}" aria-label="{{ __('Delete message') }}">
    <div class="timeline-post-delete-confirm">
        <div class="partsHeading"><h3>{{ __('Delete message') }}</h3></div>
        <div class="timeline-post-delete-confirm-context">{{ __('Delete this message?') }}</div>
        <div class="timeline-post-delete-confirm-content">
            <div class="timeline-post-member-image"><x-classic.image :file="$author?->avatar?->file" :size="48" :alt="$author?->name ?? __('%Withdrawn_member%')" /></div>
            <div class="timeline-post-content">
                <div class="timeline-member-name"><span class="screen-name">{{ $author?->name ?? __('%Withdrawn_member%') }}</span></div>
                <div class="timeline-post-body"><x-group-talk-body :message="$message" /></div>
                @include('group-talk._picture', ['message' => $message])
            </div>
            <div class="timeline-post-delete">
                <form method="POST" action="{{ route('group.talk.delete', ['group' => $message->group_id, 'message' => $message]) }}" data-timeline-delete data-error-text="{{ __('Failed to delete.') }}">
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
