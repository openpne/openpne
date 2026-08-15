@extends('layouts.classic')

@use('App\Features\Member\MemberConfigCategory')

@section('title', $aiAccount->name)

{{-- One AI account the viewer owns: where its groups are given and given up, and where it is
     deleted. Reached from the AI category of member/config, whose nav this keeps. --}}
@section('sidemenu')
    <x-member.config-sidemenu :current="MemberConfigCategory::Ai" :ai-available="true" />
@endsection

@section('content')
    <x-classic.parts id="member_ai_account" name="box" :title="$aiAccount->name">
        <div class="body">
            <p><a href="{{ route('member.profile.show', ['member' => $aiAccount->getKey()]) }}">{{ __('View profile') }}</a></p>
        </div>
    </x-classic.parts>

    @if ($groupsOn)
        <x-classic.parts id="member_ai_account_groups" name="box" :title="__('Joined %communities%')">
            <div class="body">
                @if ($joined->isEmpty())
                    <p>{{ __('This AI account has not joined any %communities%.') }}</p>
                @else
                    <ul>
                        @foreach ($joined as $group)
                            <li>
                                <a href="{{ route('group.show', ['group' => $group->getKey()]) }}">{{ $group->name }}</a>
                                <form method="POST" action="{{ route('member.config.ai.groups.quit', ['member' => $aiAccount->getKey(), 'group' => $group->getKey()]) }}">
                                    @csrf
                                    <input type="submit" class="input_submit" value="{{ __('Leave') }}">
                                </form>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </x-classic.parts>

        @if ($pending->isNotEmpty())
            <x-classic.parts id="member_ai_account_pending" name="box" :title="__('Awaiting approval')">
                <div class="body">
                    <ul>
                        @foreach ($pending as $group)
                            <li>
                                <a href="{{ route('group.show', ['group' => $group->getKey()]) }}">{{ $group->name }}</a>
                                <form method="POST" action="{{ route('member.config.ai.groups.cancel', ['member' => $aiAccount->getKey(), 'group' => $group->getKey()]) }}">
                                    @csrf
                                    <input type="submit" class="input_submit" value="{{ __('Cancel request') }}">
                                </form>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </x-classic.parts>
        @endif

        <x-classic.parts id="member_ai_account_join" name="form" :title="__('Join a %community%')">
            <form method="GET" action="{{ route('member.config.ai.show', ['member' => $aiAccount->getKey()]) }}">
                <label for="ai_group_keyword">{{ __('Keyword') }}</label>
                <input type="search" id="ai_group_keyword" name="keyword" class="input_text" value="{{ $keyword }}">
                <input type="submit" class="input_submit" value="{{ __('Search') }}">
            </form>
            @if ($browse->isEmpty())
                <p>{{ __('No %communities% found.') }}</p>
            @else
                <ul>
                    @foreach ($browse as $group)
                        <li>
                            <a href="{{ route('group.show', ['group' => $group->getKey()]) }}">{{ $group->name }}</a>
                            — {{ __($group->register_policy->label()) }}
                            @if (in_array($group->getKey(), $joinedIds, true))
                                ({{ __('Already joined') }})
                            @elseif (in_array($group->getKey(), $pendingIds, true))
                                ({{ __('Awaiting approval') }})
                            @else
                                <form method="POST" action="{{ route('member.config.ai.groups.join', ['member' => $aiAccount->getKey(), 'group' => $group->getKey()]) }}">
                                    @csrf
                                    <input type="submit" class="input_submit"
                                           value="{{ $group->register_policy === \App\Features\Group\JoinPolicy::Approval ? __('Apply to join') : __('Join') }}">
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <x-classic.pager :paginator="$browse->withQueryString()" />
            @endif
        </x-classic.parts>
    @endif

    <x-classic.parts id="member_ai_account_delete" name="form" :title="__('Delete this AI account')">
        <form method="POST" action="{{ route('member.config.ai.destroy', ['member' => $aiAccount->getKey()]) }}">
            @csrf
            <p>{{ __('Deleting is permanent. What it posted stays on the site, shown as by a withdrawn member.') }}</p>
            <div class="operation">
                <ul class="moreInfo button">
                    <li><input type="submit" class="input_submit" value="{{ __('Delete') }}"></li>
                </ul>
            </div>
        </form>
    </x-classic.parts>
@endsection
