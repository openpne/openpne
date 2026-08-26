{{-- OpenPNE 3 timelineProfile (_timelineProfile): the client-side JS stream is replaced by the
     Classic timeline's server-rendered _post rows; only the wrapper id/class is kept as the
     custom-CSS seam. Someone else's profile with no visible posts has nothing to draw, so the whole
     box is dropped rather than render an empty frame (OpenPNE 3 always emitted the shell + JS, so
     there is no server-DOM contract to preserve); the owner keeps the frame with the empty line. No
     compose path, as OpenPNE 3 had none here: posting is the home gadget's box. --}}
@php($isOwn = $subject !== null && $subject->is(auth()->user()))
@if ($posts->isNotEmpty() || $isOwn)
    @include('timeline._stylesheets')
    @include('timeline._scripts')
    <div class="dparts profileTimeline"@if ($partId !== null) id="{{ $partId }}"@endif><div class="parts">
        <div class="partsHeading"><h3>{{ __("A member's %activity%") }}</h3></div>
        @if ($posts->isNotEmpty())
            <div class="timeline" data-timeline-container>
                <div id="timeline-list">
                    @foreach ($posts as $post)
                        @include('timeline._post', ['post' => $post])
                    @endforeach
                </div>
                @if ($hasMore)
                    @include('timeline._loadmore', ['nextUrl' => route('timeline.member.rows', ['member' => $subject, 'page' => 2])])
                @endif
            </div>
        @else
            <p>{{ __('No %activity% posts to show.') }}</p>
        @endif
    </div></div>
@endif
