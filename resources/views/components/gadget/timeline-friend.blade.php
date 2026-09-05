{{-- OpenPNE 3 timelineFriend (_timelineFriend): the client-side JS stream is replaced by the Classic
     timeline's server-rendered _post rows; only the wrapper id/class is kept as the custom-CSS seam.
     Like diaryMyList, the frame and post link always render — the box is never empty for a member. --}}
@include('timeline._stylesheets')
@include('timeline._scripts')
<div class="dparts homeFriendTimeline"@if ($partId !== null) id="{{ $partId }}"@endif><div class="parts">
    <div class="partsHeading"><h3>{{ __('%Activity% of %Friend%') }}</h3></div>
    @php($canPost = \App\Features\Timeline\TimelinePosting::enabled())
    {{-- The no-JS compose path; classic-timeline-compose.js swaps it for the inline form. --}}
    @if ($canPost)
        <p data-timeline-compose-fallback><a href="{{ route('timeline.new') }}">{{ __('%Post_activity%') }}</a></p>
    @endif
    {{-- No load-more: the friend feed has no page of its own to fetch from (timeline.index is the
         whole SNS), and OpenPNE 3 keyed its own on the API this adapter does not serve. --}}
    <div class="timeline" data-timeline-container>
        @include('timeline._compose', ['returnTo' => 'home', 'canPost' => $canPost])
        @if ($posts->isNotEmpty())
            <div id="timeline-list">
                @foreach ($posts as $post)
                    @include('timeline._post', ['post' => $post, 'canPost' => $canPost])
                @endforeach
            </div>
        @endif
    </div>
</div></div>
