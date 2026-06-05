@extends('layouts.classic')

@section('title', $community->name)

@section('content')
    <div class="dparts" id="community_profile">
        <div class="partsHeading"><h3>{{ $community->name }}</h3></div>
        <div class="parts">
            @if ($community->category)
                <p class="category">{{ $community->category->name }}</p>
            @endif
            @if ($community->description)
                <p class="description">{{ $community->description }}</p>
            @endif
            <p class="memberCount">{{ __(':count members', ['count' => $community->members_count]) }}</p>

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
        </div>
    </div>
@endsection
