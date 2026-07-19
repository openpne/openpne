@extends('layouts.classic')

@section('title', $title)

@section('content')
    <div class="dparts" id="community_memberAction">
        <div class="partsHeading"><h3>{{ $title }}</h3></div>
        <div class="parts">
            <p>{{ $message }}</p>

            <form method="POST" action="{{ $actionUrl }}">
                @csrf
                <input type="hidden" name="id" value="{{ $community->getKey() }}">
                <input type="hidden" name="member_id" value="{{ $target->getKey() }}">
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ $submitLabel }}"></li>
                        <li><a href="{{ route('community.members.manage', $community) }}">{{ __('Cancel') }}</a></li>
                    </ul>
                </div>
            </form>
        </div>
    </div>
@endsection
