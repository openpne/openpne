@extends('layouts.classic')

@section('title', __('Join this %community%'))

@section('content')
    <div class="dparts" id="community_join">
        <div class="partsHeading"><h3>{{ __('Join this %community%') }}</h3></div>
        <div class="parts">
            @if ($community->register_policy === \App\Features\Community\JoinPolicy::Approval)
                <p>{{ __('This %community% requires admin approval. Send a join request to :name?', ['name' => $community->name]) }}</p>
            @else
                <p>{{ __('Join :name?', ['name' => $community->name]) }}</p>
            @endif

            <form method="POST" action="{{ route('community.join') }}">
                @csrf
                <input type="hidden" name="id" value="{{ $community->getKey() }}">
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('Join') }}"></li>
                        <li><a href="{{ route('community.show', $community) }}">{{ __('Cancel') }}</a></li>
                    </ul>
                </div>
            </form>
        </div>
    </div>
@endsection
