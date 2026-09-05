@extends('layouts.classic')

@php($title = $owner->is(auth()->user()) ? __('%Activity%') : __(":name's %activity%", ['name' => $owner->name]))

@section('title', $title)

@section('content')
    @php($canPost = \App\Features\Timeline\TimelinePosting::enabled())
    @include('timeline._stylesheets')
    @include('timeline._scripts')
    {{-- OpenPNE 3 streams the posts client-side from the API; the Classic adapter renders them
         server-side. The id is _timelineProfile.php's non-gadget branch, which suffixes the member
         id (the gadget branch suffixes the gadget id into the same prefix). No compose path here,
         as OpenPNE 3 had none: posting is the home gadget's box. --}}
    <x-classic.parts :id="'profileTimeline_'.$owner->getKey()" name="profileTimeline" :title="$title">
        @if ($posts->isEmpty())
            <p>{{ __('No %activity% posts to show.') }}</p>
        @else
            {{-- OpenPNE 3's div.timeline > div#timeline-list shell with the load-more control; the
                 server pager beside it is the way on without the script. --}}
            <div class="timeline" data-timeline-container>
                <div id="timeline-list">
                    @foreach ($posts as $post)
                        @include('timeline._post', ['post' => $post, 'canPost' => $canPost])
                    @endforeach
                </div>
                @if ($posts->hasMorePages())
                    @include('timeline._loadmore', ['nextUrl' => route('timeline.member.rows', ['member' => $owner, 'page' => $posts->currentPage() + 1])])
                @endif
            </div>

            <div data-timeline-pager><x-classic.pager :paginator="$posts" /></div>
        @endif
    </x-classic.parts>
@endsection
