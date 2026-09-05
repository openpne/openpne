{{-- OpenPNE 3 activityBox (default/_activityBox via op_include_box): a server-rendered activity list.
     OpenPNE 3 drew the box when it had activities or a form, and the home supplied the form only
     while is_allow_post_activity was on; the form becomes a post-page link under the same rule, so an
     empty box with posting off is dropped, as a profile activityBox (never a form) always was. --}}
@php($offersPost = $context === 'home' && \App\Features\Timeline\TimelinePosting::enabled())
@if ($posts->isNotEmpty() || $offersPost)
    <div class="dparts box activityBox homeRecentList" id="{{ $partId }}"><div class="parts">
        <div class="partsHeading"><h3>{{ $title }}</h3></div>
        <div class="body">
            @if ($offersPost)
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
