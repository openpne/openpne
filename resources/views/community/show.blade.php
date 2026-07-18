@extends('layouts.classic')

@section('title', $community->name)

@section('sidemenu')
    <x-community.sidemenu :community="$community" :members="$sidebarMembers" />
@endsection

@if ($isPending)
    {{-- The pending-approval notice, shown only while waiting. --}}
    @section('top')
        <div class="dparts" id="community_pending">
            <div class="parts">
                <p>{{ __('You are waiting for the participation approval by %community% administrator.') }}</p>
            </div>
        </div>
    @endsection
@endif

@section('content')
    {{-- The OpenPNE 3 community details listBox (homeSuccess center column): a th/td table of the
         community's profile fields, followed by the member operations. --}}
    <x-gadget-part part-id="communityHome" part-name="listBox" :title="__('%Community%')">
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

        <div class="operation">
            <ul class="moreInfo button">
                <li><a href="{{ route('community.members', ['id' => $community->getKey()]) }}">{{ __('Member list') }}</a></li>

                @if ($role === null && ! $isPending)
                    <li><a href="{{ route('community.join.show', ['id' => $community->getKey()]) }}">{{ __('Join this %community%') }}</a></li>
                @elseif ($isPending)
                    <li><span class="pending">{{ __('Your join request is pending.') }}</span></li>
                @endif

                @if ($role?->canManage())
                    <li><a href="{{ route('community.edit', ['id' => $community->getKey()]) }}">{{ __('Edit settings') }}</a></li>
                @endif
                @if ($role === \App\Features\Community\CommunityRole::Admin)
                    <li><a href="{{ route('community.members.pending', ['id' => $community->getKey()]) }}">{{ __('Pending members') }}</a></li>
                    <li><a href="{{ route('community.delete.show', $community) }}">{{ __('Delete %community%') }}</a></li>
                @elseif ($role !== null)
                    <li><a href="{{ route('community.quit.show', ['id' => $community->getKey()]) }}">{{ __('Leave this %community%') }}</a></li>
                @endif
            </ul>
        </div>
    </x-gadget-part>

    {{-- The recent-topics box links into the board. Shown only when the viewer may read the
         board (a members-only board is hidden from non-members). --}}
    @isset($recentTopics)
        <div class="dparts" id="community_recentTopics">
            <div class="partsHeading"><h3>{{ __('Recent %topics%') }}</h3></div>
            <div class="parts">
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
            </div>
        </div>
    @endisset

    {{-- The recent-events box links into the event board, shown only when the viewer may read
         the board (events share the topic read gate). --}}
    @isset($recentEvents)
        <div class="dparts" id="community_recentEvents">
            <div class="partsHeading"><h3>{{ __('Recent events') }}</h3></div>
            <div class="parts">
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
            </div>
        </div>
    @endisset
@endsection
