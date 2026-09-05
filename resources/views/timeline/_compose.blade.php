@if (\App\Features\Timeline\TimelinePosting::enabled())
@once
    @push('pluginCss')
        <link rel="stylesheet" href="{{ asset('opTimelinePlugin/css/counter.css') }}">
    @endpush
@endonce
<form method="POST" action="{{ route('timeline.store') }}" enctype="multipart/form-data" data-timeline-compose hidden
      data-timeline-mention data-mention-candidates-url="{{ route('timeline.mention_candidates') }}" data-mention-no-image-url="{{ asset('images/no_image.gif') }}" data-mention-label="{{ __('Mention candidates') }}">
    @csrf
    @include('timeline._mention-draft')
    <input type="hidden" name="return_to" value="{{ $returnTo }}">
    <div class="timeline-postform well">
        {{-- No maxlength: it measures UTF-16 units, so it would block a body of 140 astral code
             points the counter and the server both accept. The JS gate and max:140 bound this. --}}
        <textarea id="timeline-textarea" name="body" class="input-xlarge" rows="1" placeholder="{{ __('What are you doing?') }}">{{ old('body') }}</textarea>
        {{-- OpenPNE 3's error seams, fed server-side after a failed POST. --}}
        @if ($errors->has('body') || $errors->has('visibility'))
            <div id="timeline-submit-error" style="display: block;">{{ $errors->first('body') ?: $errors->first('visibility') }}</div>
        @endif
        @error('image')
            <div id="timeline-upload-error" style="display: block;">{{ $message }}</div>
        @enderror
        <div id="timeline-submit-area">
            <span id="timeline-upload-photo-button" class="btn"><i class="icon-camera"></i></span>
            <span id="photo-remove"><span class="icon-remove"></span></span><span id="photo-file-name"></span>
            <span id="counter"></span>
            <select id="timeline-public-flag" name="visibility" aria-label="{{ __('Visibility') }}">
                @foreach (\App\Features\Timeline\TimelineVisibility::options() as $option)
                    <option value="{{ $option->value }}" @selected(old('visibility', \App\Support\Visibility::Members->value) == $option->value)>{{ __($option->label()) }}</option>
                @endforeach
            </select>
            <input id="timeline-submit-upload" type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp">
            <button type="submit" id="timeline-submit-button" class="btn btn-primary timeline-submit">{{ __('Post an %activity%') }}</button>
        </div>
    </div>
</form>
@once
    <script src="{{ asset('js/classic-timeline-compose.js') }}" defer></script>
@endonce
@include('timeline._mention-picker')
@endif
