@extends('layouts.classic')

@section('title', __('Send a %friend% request'))

@section('content')
    <x-classic.parts id="friendLink" name="form" :title="__('Send a %friend% request')">
        <form method="POST" action="{{ route('friend.link') }}">
            @csrf
            <input type="hidden" name="target_id" value="{{ $target->getKey() }}">
            <div class="block">{{ __('Send a %friend% request to :name?', ['name' => $target->name]) }}</div>
            <div class="operation">
                <ul class="moreInfo button">
                    <li><input type="submit" class="input_submit" value="{{ __('Send request') }}"></li>
                    <li><a href="{{ route('friend.list') }}">{{ __('Cancel') }}</a></li>
                </ul>
            </div>
        </form>
    </x-classic.parts>
@endsection
