{{-- OpenPNE 3 allMemberActivityBox (member/_allMemberActivityBox via op_include_box): the whole SNS's
     members-only activity. When the post form is enabled (is_viewable_activity_form) the frame always
     renders and offers a post entry point; otherwise an empty box is dropped. --}}
@if ($posts->isNotEmpty() || $showForm)
    <div class="dparts box activityBox homeRecentList" id="{{ $partId }}"><div class="parts">
        <div class="partsHeading"><h3>{{ __("SNS Member's %activity%") }}</h3></div>
        <div class="body">
            @if ($showForm && \App\Features\Timeline\TimelinePosting::enabled())
                {{-- OpenPNE 3 rendered an inline post form here; the Classic port links to the post page. --}}
                <p><a href="{{ route('timeline.new') }}">{{ __('%Post_activity%') }}</a></p>
            @endif
            <div class="box_list">
                <ol id="{{ $partId }}_timeline" class="activities">
                    @foreach ($posts as $post)
                        @include('components.gadget._activity-record', ['post' => $post])
                    @endforeach
                </ol>
            </div>
        </div>
        <div class="moreInfo"><ul class="moreInfo">
            <li><a href="{{ route('timeline.index') }}">{{ __('More') }}</a></li>
        </ul></div>
    </div></div>
@endif
