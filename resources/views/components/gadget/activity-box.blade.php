{{-- OpenPNE 3 activityBox (default/_activityBox via op_include_box): a server-rendered activity list.
     On the home, OpenPNE 3 supplied the inline post form while is_allow_post_activity was on, so the
     frame always renders and the form becomes a post-page link under the same switch. A profile
     activityBox never had a form, so an empty one is dropped. --}}
@if ($posts->isNotEmpty() || $context === 'home')
    <div class="dparts box activityBox homeRecentList" id="{{ $partId }}"><div class="parts">
        <div class="partsHeading"><h3>{{ $title }}</h3></div>
        <div class="body">
            @if ($context === 'home' && \App\Features\Timeline\TimelinePosting::enabled())
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
            <li><a href="{{ $moreUrl }}">{{ __('More') }}</a></li>
        </ul></div>
    </div></div>
@endif
