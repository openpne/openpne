{{-- OpenPNE 3 activityBox (default/_activityBox via op_include_box): a server-rendered activity list.
     Read-only in both contexts — the OpenPNE 3 inline post form is ported only on allMemberActivityBox
     — so an empty box is dropped rather than render an empty frame (OpenPNE 3's profile activityBox and
     an all-off home box drop the same way). --}}
@if ($posts->isNotEmpty())
    <div class="dparts box activityBox homeRecentList" id="{{ $partId }}"><div class="parts">
        <div class="partsHeading"><h3>{{ $title }}</h3></div>
        <div class="body">
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
