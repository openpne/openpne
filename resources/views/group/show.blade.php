@extends('layouts.classic')

@section('title', $group->name)

{{-- OpenPNE 3 loads communityTopic.css here through the embedded topic/event list components'
     addStylesheet, not the community module's view.yml — so the link is pushed by this screen,
     and the module map (PluginStylesheets) stays silent for community. The components add it
     inside their view ACL, so a viewer who gets no board rows gets no stylesheet either
     ($recentTopics/$recentEvents are null exactly then). --}}
@if (isset($recentTopics) || isset($recentEvents))
    @push('pluginCss')
        <link rel="stylesheet" href="{{ asset('opCommunityTopicPlugin/css/communityTopic.css') }}">
    @endpush
@endif

@section('sidemenu')
    <x-group.sidemenu :group="$group" :members="$sidebarMembers" :can-manage-members="$role?->canManage() ?? false" />
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
                 OpenPNE 3 routed the decision through its confirmation center, so this box is
                 OpenPNE 4-native and keeps its own id; the yes/no shape is the OpenPNE 3 one. --}}
            <x-classic.parts id="community_changeAdminRequest" name="yesNo">
                <div class="block">{{ __('The administrator of this %community% asks you to take over the administration.') }}</div>
                <div class="operation">
                    <ul class="moreInfo button">
                        <li>
                            <form method="POST" action="{{ route('group.members.transfer.accept', ['group' => $group]) }}">
                                @csrf
                                <input type="hidden" name="id" value="{{ $group->getKey() }}">
                                <input type="submit" class="input_submit" value="{{ __('Accept') }}">
                            </form>
                        </li>
                        <li>
                            <form method="POST" action="{{ route('group.members.transfer.reject', ['group' => $group]) }}">
                                @csrf
                                <input type="hidden" name="id" value="{{ $group->getKey() }}">
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
    {{-- OpenPNE 3's plugin view customize injected the community timeline before the communityHome
         part (modules/community/config/view.yml, target: before), and gated the box on membership
         — non-members cannot post into it, so an empty compose box would be an invitation to a
         refusal. Absent when the timeline unit is off. --}}
    @isset($timelinePosts)
        @include('timeline._community-box', [
            'group' => $group,
            'posts' => $timelinePosts,
            'canPost' => true,
            'title' => __('%Activity%'),
        ])
    @endisset

    {{-- The OpenPNE 3 community details listBox (homeSuccess center column): a th/td table of the
         community's profile fields, followed by the member operations. --}}
    <x-classic.parts id="communityHome" name="listBox" :title="__('%Community%')">
        <table>
            <tr>
                <th>{{ __('%Community% Name') }}</th>
                <td>{{ $group->name }}</td>
            </tr>
            @if ($group->category)
                <tr>
                    <th>{{ __('%Community% Category') }}</th>
                    <td>{{ $group->category->name }}</td>
                </tr>
            @endif
            <tr>
                <th>{{ __('Date Created') }}</th>
                <td>{{ \App\Support\LocalizedDate::date($group->created_at) }}</td>
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
            {{-- The config rows follow OpenPNE 3's registry order (topic access, then register
                 policy, then description), taken from a live OpenPNE 3 render — the source
                 builds them from its community-config registry, so the shipped order is the
                 template's own. --}}
            <tr>
                <th>{{ __('Count of Members') }}</th>
                <td>{{ $group->members_count }}</td>
            </tr>
            <tr>
                <th>{{ __('Authority to Read %Topic%') }}</th>
                <td>{{ __($group->topic_read_access->label()) }}</td>
            </tr>
            <tr>
                <th>{{ __('Authority to Create %Topic%') }}</th>
                <td>{{ __($group->topic_post_authority->label()) }}</td>
            </tr>
            <tr>
                <th>{{ __('Register policy') }}</th>
                <td>{{ __($group->register_policy->label()) }}</td>
            </tr>
            {{-- OpenPNE 3 renders the description row even when empty. --}}
            <tr>
                <th>{{ __('%Community% Description') }}</th>
                <td>@if ($group->description)<x-user-text :value="$group->description" />@endif</td>
            </tr>
            {{-- The OpenPNE 3 recent-event and recent-topic rows (communityTopic plugin's lastRow
                 customize, events first). A row renders only when the viewer may read the board
                 ($recentEvents/$recentTopics are null otherwise); with no entries it still shows,
                 message-less, carrying the create link. More appears only over a non-empty list. --}}
            @isset($recentEvents)
                <tr class="communityEvent">
                    <th>{{ __('%Community% Events') }}</th>
                    <td>
                        @unless ($recentEvents->isEmpty())
                            <ul class="articleList">
                                @foreach ($recentEvents as $event)
                                    <li><span class="date">{{ \App\Support\LocalizedDate::monthDay($event->updated_at, app()->getLocale()) }}</span> <a href="{{ route('group.events.show', $event) }}">{{ \App\Features\Group\GroupPostTitle::withCount($event) }}</a></li>
                                @endforeach
                            </ul>
                        @endunless
                        <div class="moreInfo">
                            <ul class="moreInfo">
                                @unless ($recentEvents->isEmpty())
                                    <li><a href="{{ route('group.events.index', $group) }}">{{ __('More') }}</a></li>
                                @endunless
                                @if ($canPostEvent)
                                    <li><a href="{{ route('group.events.new', $group) }}">{{ __('Create a new event') }}</a></li>
                                @endif
                            </ul>
                        </div>
                    </td>
                </tr>
            @endisset
            @isset($recentTopics)
                <tr class="communityTopic">
                    <th>{{ __('%Community% %Topics%') }}</th>
                    <td>
                        @unless ($recentTopics->isEmpty())
                            <ul class="articleList">
                                @foreach ($recentTopics as $topic)
                                    <li><span class="date">{{ \App\Support\LocalizedDate::monthDay($topic->updated_at, app()->getLocale()) }}</span> <a href="{{ route('group.topics.show', $topic) }}">{{ \App\Features\Group\GroupPostTitle::withCount($topic) }}</a></li>
                                @endforeach
                            </ul>
                        @endunless
                        <div class="moreInfo">
                            <ul class="moreInfo">
                                @unless ($recentTopics->isEmpty())
                                    <li><a href="{{ route('group.topics.index', $group) }}">{{ __('More') }}</a></li>
                                @endunless
                                @if ($canPostTopic)
                                    <li><a href="{{ route('group.topics.new', $group) }}">{{ __('Create a new %topic%') }}</a></li>
                                @endif
                            </ul>
                        </div>
                    </td>
                </tr>
            @endisset
        </table>
    </x-classic.parts>

    {{-- OpenPNE 3 homeSuccess.php closes the page with a class-less ul after the listBox, holding
         only the membership operations: the roster lives in the sidemenu and delete inside the edit
         screen. The administrator cannot leave (they must hand the community over first); the join
         entry is withheld from a pending applicant (see below). --}}
    <ul>
        @if ($role?->canManage())
            <li><a href="{{ route('group.edit', ['id' => $group->getKey()]) }}">{{ __('Edit this %community%') }}</a></li>
        @endif
        @if ($role !== \App\Features\Group\GroupRole::Admin)
            @if ($role === null)
                {{-- OpenPNE 3 shows Join to a pending applicant too, but its join page rendered an
                     error there; OpenPNE 4's confirm redirects a pending viewer straight back, so
                     the link would be a no-op — the waiting notice above carries the state. --}}
                @unless ($isPending)
                    <li><a href="{{ route('group.join.show', ['group' => $group->getKey()]) }}">{{ __('Join this %community%') }}</a></li>
                @endunless
            @else
                <li><a href="{{ route('group.quit.show', ['group' => $group->getKey()]) }}">{{ __('Leave this %community%') }}</a></li>
            @endif
        @endif
        {{-- OpenPNE 4-native: OpenPNE 3 reached the join queue from its confirmation center, which
             is not ported, so this page is the only way in. --}}
        @if ($role === \App\Features\Group\GroupRole::Admin)
            <li><a href="{{ route('group.members.pending', ['group' => $group->getKey()]) }}">{{ __('Pending members') }}</a></li>
        @endif
    </ul>
@endsection
