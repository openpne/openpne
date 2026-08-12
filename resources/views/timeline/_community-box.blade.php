{{-- The community timeline box. OpenPNE 3 rendered one component (timeline/timelineCommunity) in
     both places — the community home, injected before the communityHome part by the plugin's view
     customize, and the standalone page, which included the same component — so the two share this
     partial rather than drifting into two boxes.

     `$pager` renders the page's pager; the home box shows none, as no home box does. --}}
@include('timeline._stylesheets')
<x-classic.parts id="communityTimeline" name="communityTimeline" :title="$title">
    @if ($canPost)
        {{-- The no-JS compose path; classic-timeline-compose.js swaps it for the inline form. It
             must reach a real form: the inline one ships hidden, so a link back to this page would
             leave a reader without script unable to post at all. --}}
        <p data-timeline-compose-fallback>
            <a href="{{ route('community.timeline.new', ['community' => $community]) }}">{{ __('%Post_activity%') }}</a>
        </p>
    @endif

    <div class="timeline">
        @if ($canPost)
            @include('timeline._compose', ['community' => $community])
        @endif
        <div id="timeline-list">
            @foreach ($posts as $post)
                @include('timeline._post', ['post' => $post])
            @endforeach
        </div>
    </div>

    @if (count($posts) === 0)
        <p>{{ __('No %activity% posts to show.') }}</p>
    @elseif ($pager ?? false)
        <x-classic.pager :paginator="$posts" />
    @else
        {{-- The home box's way to the full page. The compose fallback link above is not it: the
             script hides that one the moment it swaps in the inline form. --}}
        <div class="moreInfo">
            <ul class="moreInfo">
                <li><a href="{{ route('community.timeline', ['community' => $community]) }}">{{ __('Show all') }}</a></li>
            </ul>
        </div>
    @endif
</x-classic.parts>
