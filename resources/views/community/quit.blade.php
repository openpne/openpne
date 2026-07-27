@extends('layouts.classic')

@section('title', __('Leave this %community%'))

@section('content')
    <x-classic.parts id="communityQuiting" name="form" :title="__('Leave this %community%')">
        <form method="POST" action="{{ route('community.quit') }}">
            @csrf
            <input type="hidden" name="id" value="{{ $community->getKey() }}">
            <div class="block">{{ __('Leave :name?', ['name' => $community->name]) }}</div>
            <div class="operation">
                <ul class="moreInfo button">
                    <li><input type="submit" class="input_submit" value="{{ __('Leave this %community%') }}"></li>
                    <li><a href="{{ route('community.show', $community) }}">{{ __('Cancel') }}</a></li>
                </ul>
            </div>
        </form>
    </x-classic.parts>
@endsection
