@extends('layouts.classic')

@section('title', $community->name)

@section('sidemenu')
    <x-community.sidemenu :community="$community" :members="$sidebarMembers" :can-manage-members="$role?->canManage() ?? false" />
@endsection

@if ($isPending || $isTransferNominee)
    {{-- One Top section: the two notices are mutually exclusive (a nominee is a member, a pending
         applicant is not), and two @section('top') blocks would collide. --}}
    @section('top')
        @if ($isPending)
            {{-- The pending-approval notice, shown only while waiting. --}}
            <x-classic.parts id="informationAboutCommunity" name="descriptionBox">
                <div class="body">{{ __('You are waiting for the participation approval by %community% administrator.') }}</div>
            </x-classic.parts>
        @else
            {{-- The admin-transfer nominee's accept/reject banner (this is the confirmation step).
                 OpenPNE 3 routed the decision through its confirmation centre, so this box is
                 OpenPNE 4-native and keeps its own id; the yes/no shape is the OpenPNE 3 one. --}}
            <x-classic.parts id="community_changeAdminRequest" name="yesNo">
                <div class="block">{{ __('The administrator of this %community% asks you to take over the administration.') }}</div>
                <div class="operation">
                    <ul class="moreInfo button">
                        <li>
                            <form method="POST" action="{{ route('community.members.transfer.accept') }}">
                                @csrf
                                <input type="hidden" name="id" value="{{ $community->getKey() }}">
                                <input type="submit" class="input_submit" value="{{ __('Accept') }}">
                            </form>
                        </li>
                        <li>
                            <form method="POST" action="{{ route('community.members.transfer.reject') }}">
                                @csrf
                                <input type="hidden" name="id" value="{{ $community->getKey() }}">
                                <input type="submit" class="input_submit" value="{{ __('Decline') }}">
                            </form>
                        </li>
                    </ul>
                </div>
            </x-classic.parts>
        @endif
    @endsection
@endif

@section('content')
    {{-- The OpenPNE 3 community details listBox (homeSuccess center column): a th/td table of the
         community's profile fields, followed by the member operations. --}}
    <x-classic.parts id="communityHome" name="listBox" :title="__('%Community%')">
        <table>
            <tr>
                <th>{{ __('%Community% Name') }}</th>
                <td>{{ $community->name }}</td>
            </tr>
            @if ($community->category)
                <tr>
                    <th>{{ __('%Community% Category') }}</th>
                    <td>{{ $community->category->name }}</td>
                </tr>
            @endif
            <tr>
                <th>{{ __('Date Created') }}</th>
                <td>{{ \App\Support\LocalizedDate::date($community->created_at) }}</td>
            </tr>
            @if ($adminMember)
                <tr>
                    <th>{{ __('Administrator') }}</th>
                    <td><a href="{{ route('member.profile.show', $adminMember) }}">{{ $adminMember->name }}</a></td>
                </tr>
            @endif
            @if ($subAdminMembers->isNotEmpty())
                <tr>
                    <th>{{ __('Sub Administrator') }}</th>
                    <td>
                        <ul>
                            @foreach ($subAdminMembers as $subAdmin)
                                <li><a href="{{ route('member.profile.show', $subAdmin) }}">{{ $subAdmin->name }}</a></li>
                            @endforeach
                        </ul>
                    </td>
                </tr>
            @endif
            <tr>
                <th>{{ __('Count of Members') }}</th>
                <td>{{ $community->members_count }}</td>
            </tr>
            <tr>
                <th>{{ __('Register policy') }}</th>
                <td>{{ __($community->register_policy->label()) }}</td>
            </tr>
            {{-- OpenPNE 3 renders the description row even when empty. --}}
            <tr>
                <th>{{ __('%Community% Description') }}</th>
                <td>@if ($community->description)<x-user-text :value="$community->description" />@endif</td>
            </tr>
            <tr>
                <th>{{ __('Authority to Read %Topic%') }}</th>
                <td>{{ __($community->topic_read_access->label()) }}</td>
            </tr>
            <tr>
                <th>{{ __('Authority to Create %Topic%') }}</th>
                <td>{{ __($community->topic_post_authority->label()) }}</td>
            </tr>
        </table>
    </x-classic.parts>

    {{-- OpenPNE 3 homeSuccess.php closes the page with a class-less ul outside the listBox, holding
         only the membership operations: the roster lives in the sidemenu and delete inside the edit
         screen. The administrator cannot leave (they must hand the community over first), and the
         join entry keys off membership alone, so an approval applicant still gets it. --}}
    <ul>
        @if ($role?->canManage())
            <li><a href="{{ route('community.edit', ['id' => $community->getKey()]) }}">{{ __('Edit this %community%') }}</a></li>
        @endif
        @if ($role !== \App\Features\Community\CommunityRole::Admin)
            @if ($role === null)
                {{-- OpenPNE 3 shows Join to a pending applicant too, but its join page rendered an
                     error there; OpenPNE 4's confirm redirects a pending viewer straight back, so
                     the link would be a no-op — the waiting notice above carries the state. --}}
                @unless ($isPending)
                    <li><a href="{{ route('community.join.show', ['id' => $community->getKey()]) }}">{{ __('Join this %community%') }}</a></li>
                @endunless
            @else
                <li><a href="{{ route('community.quit.show', ['id' => $community->getKey()]) }}">{{ __('Leave this %community%') }}</a></li>
            @endif
        @endif
        {{-- OpenPNE 4-native: OpenPNE 3 reached the join queue from its confirmation centre, which
             is not ported, so this page is the only way in. --}}
        @if ($role === \App\Features\Community\CommunityRole::Admin)
            <li><a href="{{ route('community.members.pending', ['id' => $community->getKey()]) }}">{{ __('Pending members') }}</a></li>
        @endif
    </ul>

    {{-- The recent-topics box links into the board. Shown only when the viewer may read the
         board (a members-only board is hidden from non-members). OpenPNE 3 listed the same
         entries as a row of the communityHome table above, so this box carries no OpenPNE 3
         kind or id; folding it back into that table is a content change, not a frame one. --}}
    @isset($recentTopics)
        <x-classic.parts id="community_recentTopics" :title="__('Recent %topics%')">
            @if ($recentTopics->isEmpty())
                <p>{{ __('No %topics% to show.') }}</p>
            @else
                <ul class="topicList">
                    @foreach ($recentTopics as $topic)
                        <li>
                            <a href="{{ route('communityTopic.show', $topic) }}">{{ $topic->name }} ({{ $topic->comments_count }})</a>
                        </li>
                    @endforeach
                </ul>
            @endif

            <div class="operation">
                <ul class="moreInfo button">
                    <li><a href="{{ route('communityTopic.index', $community) }}">{{ __('See all %topics%') }}</a></li>
                    @if ($canPostTopic)
                        <li><a href="{{ route('communityTopic.new', $community) }}">{{ __('Post a new %topic%') }}</a></li>
                    @endif
                </ul>
            </div>
        </x-classic.parts>
    @endisset

    {{-- The recent-events box links into the event board, shown only when the viewer may read
         the board (events share the topic read gate). Same OpenPNE 3 lineage as recentTopics. --}}
    @isset($recentEvents)
        <x-classic.parts id="community_recentEvents" :title="__('Recent events')">
            @if ($recentEvents->isEmpty())
                <p>{{ __('No events to show.') }}</p>
            @else
                <ul class="topicList">
                    @foreach ($recentEvents as $event)
                        <li>
                            <a href="{{ route('communityEvent.show', $event) }}">{{ $event->name }} ({{ $event->comments_count }})</a>
                            <span class="eventOpenDate">{{ \App\Support\LocalizedDate::date($event->open_date) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif

            <div class="operation">
                <ul class="moreInfo button">
                    <li><a href="{{ route('communityEvent.index', $community) }}">{{ __('See all events') }}</a></li>
                    @if ($canPostEvent)
                        <li><a href="{{ route('communityEvent.new', $community) }}">{{ __('Post a new event') }}</a></li>
                    @endif
                </ul>
            </div>
        </x-classic.parts>
    @endisset
@endsection
