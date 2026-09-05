{{-- OpenPNE 3 timelineAll (_timelineAll): the client-side JS stream is replaced by the Classic
     timeline's server-rendered _post rows; only the wrapper id/class is kept as the custom-CSS seam.
     Like diaryMyList, the frame and post link always render — the box is never empty for a member. --}}
@include('timeline._stylesheets')
@include('timeline._scripts')
<div class="dparts homeAllTimeline"@if ($partId !== null) id="{{ $partId }}"@endif><div class="parts">
    <div class="partsHeading"><h3>{{ __("All members' %activity%") }}</h3></div>
    {{-- The no-JS compose path; classic-timeline-compose.js swaps it for the inline form. --}}
    @if (\App\Features\Timeline\TimelinePosting::enabled())
        <p data-timeline-compose-fallback><a href="{{ route('timeline.new') }}">{{ __('%Post_activity%') }}</a></p>
    @endif
    <div class="timeline" data-timeline-container>
        @include('timeline._compose', ['returnTo' => 'home'])
        @if ($posts->isNotEmpty())
            <div id="timeline-list">
                @foreach ($posts as $post)
                    @include('timeline._post', ['post' => $post])
                @endforeach
            </div>
        @endif
        @if ($hasMore)
            @include('timeline._loadmore', ['nextUrl' => route('timeline.index.rows', ['page' => 2, 'per_page' => $limit])])
        @endif
    </div>
</div></div>
