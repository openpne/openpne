{{-- OpenPNE 3 timelineProfile (_timelineProfile): the client-side JS stream is replaced by the
     Classic timeline's server-rendered _post rows; only the wrapper id/class is kept as the
     custom-CSS seam. Someone else's profile with no visible posts has neither rows nor a post link,
     so the whole box is dropped rather than render an empty frame (OpenPNE 3 always emitted the
     shell + JS, so there is no server-DOM contract to preserve). --}}
@php($isOwn = $subject !== null && $subject->is(auth()->user()))
@if ($posts->isNotEmpty() || $isOwn)
    <div class="dparts profileTimeline"@if ($partId !== null) id="{{ $partId }}"@endif><div class="parts">
        <div class="partsHeading"><h3>{{ __("A member's %activity%") }}</h3></div>
        @if ($isOwn)
            <p><a href="{{ route('timeline.new') }}">{{ __('%Post_activity%') }}</a></p>
        @endif
        @if ($posts->isNotEmpty())
            <ul class="timeline-list">
                @foreach ($posts as $post)
                    @include('timeline._post', ['post' => $post])
                @endforeach
            </ul>
        @endif
    </div></div>
@endif
