{{-- The OpenPNE 3 inline compose box (_timelineAll's timeline-postform), as a real form posting
     to timeline.store. It ships hidden: the plugin CSS assumes the OpenPNE 3 scripts (the submit
     area is display:none until focus, the file input sits offscreen), so without
     classic-timeline-compose.js the box would be a trap — the %Post_activity% link beside it
     stays the no-JS path, and the script swaps them. `$returnTo` names the page's allowlisted
     return token. OpenPNE 3's fixed ids stay as CSS seams; two home gadgets may repeat them, so
     the script scopes by form, never by document id.

     `$community` set posts into that community instead: the audience is the community, so the
     visibility select has nothing to offer and is not rendered, and the picker asks for the
     community's members. --}}
@php($community = $community ?? null)
@once
    @push('pluginCss')
        <link rel="stylesheet" href="{{ asset('opTimelinePlugin/css/counter.css') }}">
    @endpush
@endonce
<form method="POST" action="{{ $community ? route('community.timeline.store', ['community' => $community]) : route('timeline.store') }}" enctype="multipart/form-data" data-timeline-compose hidden
      data-timeline-mention data-mention-candidates-url="{{ $community ? route('timeline.mention_candidates', ['community' => $community]) : route('timeline.mention_candidates') }}" data-mention-no-image-url="{{ asset('images/no_image.gif') }}" data-mention-label="{{ __('Mention candidates') }}">
    @csrf
    @include('timeline._mention-draft')
    @if (! $community)
        <input type="hidden" name="return_to" value="{{ $returnTo }}">
    @endif
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
            @unless ($community)
                <select id="timeline-public-flag" name="visibility">
                    @foreach (\App\Features\Timeline\TimelineVisibility::options() as $option)
                        <option value="{{ $option->value }}" @selected(old('visibility', \App\Support\Visibility::Members->value) == $option->value)>{{ __($option->label()) }}</option>
                    @endforeach
                </select>
            @endunless
            <input id="timeline-submit-upload" type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp">
            <button type="submit" id="timeline-submit-button" class="btn btn-primary timeline-submit">{{ __('Post an %activity%') }}</button>
        </div>
    </div>
</form>
@once
    <script src="{{ asset('js/classic-timeline-compose.js') }}" defer></script>
@endonce
@include('timeline._mention-picker')
