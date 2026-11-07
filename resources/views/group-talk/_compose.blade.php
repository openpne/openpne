@once
    @push('pluginCss')
        <link rel="stylesheet" href="{{ asset('opTimelinePlugin/css/counter.css') }}">
    @endpush
@endonce
{{-- OpenPNE 3's community compose box (no public-flag select: the group is the audience). Never
     hidden: talk has no standalone compose page to fall back to, so the form stands without the
     script and classic-timeline-compose.js only enhances it (timeline-nojs leaves when it does). --}}
<form method="POST" action="{{ route('group.talk.store', $group) }}" enctype="multipart/form-data" class="timeline-nojs"
      data-timeline-compose data-max-length="{{ \App\Features\GroupTalk\TalkBody::MAX }}" data-allow-image-only
      data-timeline-mention data-mention-candidates-url="{{ route('group.talk.mention_candidates', $group) }}" data-mention-label="{{ __('Mention candidates') }}">
    @csrf
    @include('timeline._mention-draft')
    @if ($replyTo !== null)
        <input type="hidden" name="reply_to_message_id" value="{{ $replyTo->getKey() }}">
        <p class="timeline-post-reply-to">{{ __('Replying to :name', ['name' => $replyTo->author?->name ?? __('%Withdrawn_member%')]) }} <a href="{{ route('group.talk.show', $group) }}#timeline-textarea">{{ __('Cancel') }}</a></p>
    @endif
    <div class="timeline-postform well">
        <textarea id="timeline-textarea" name="body" class="input-xlarge" rows="1" placeholder="{{ __('What are you doing?') }}" aria-label="{{ __('Message') }}">{{ old('body') }}</textarea>
        @php($submitError = $errors->first('body') ?: $errors->first('reply_to_message_id') ?: collect($errors->keys())->diff(['image'])->map(fn (string $key) => $errors->first($key))->first())
        @if ($submitError)
            <div id="timeline-submit-error" style="display: block;">{{ $submitError }}</div>
        @endif
        @if ($errors->has('image'))
            <div id="timeline-upload-error" style="display: block;">{{ $errors->first('image') }}</div>
        @endif
        <div id="timeline-submit-area">
            <span id="timeline-upload-photo-button" class="btn"><i class="icon-camera"></i></span>
            <span id="photo-remove"><span class="icon-remove"></span></span><span id="photo-file-name"></span>
            <span id="counter"></span>
            <input id="timeline-submit-upload" type="file" name="image" aria-label="{{ __('Image') }}">
            <button type="submit" id="timeline-submit-button" class="btn btn-primary timeline-submit">{{ __('Post') }}</button>
        </div>
    </div>
</form>
@once
    <script src="{{ asset('js/classic-timeline-compose.js') }}" defer></script>
@endonce
@include('timeline._mention-picker')
