{{-- OpenPNE 3 timelineFriend (_timelineFriend): the client-side JS stream is replaced by the Classic
     timeline's server-rendered _post rows; only the wrapper id/class is kept as the custom-CSS seam.
     Like diaryMyList, the frame and post link always render — the box is never empty for a member. --}}
<div class="dparts homeFriendTimeline"@if ($partId !== null) id="{{ $partId }}"@endif><div class="parts">
    <div class="partsHeading"><h3>{{ __('%Activity% of %Friend%') }}</h3></div>
    <p><a href="{{ route('timeline.new') }}">{{ __('%Post_activity%') }}</a></p>
    @if ($posts->isNotEmpty())
        <ul class="timeline-list">
            @foreach ($posts as $post)
                @include('timeline._post', ['post' => $post])
            @endforeach
        </ul>
    @endif
</div></div>
