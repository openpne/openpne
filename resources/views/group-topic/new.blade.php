@extends('layouts.classic')

@section('title', __('Post a new %topic%'))

@section('content')
    {{-- OpenPNE 3 names the create and edit boxes alike (formCommunityTopic). --}}
    <x-classic.parts id="formCommunityTopic" name="form" :title="__('Post a new %topic%')">
        <form method="POST" action="{{ route('group.topics.store', $group) }}" enctype="multipart/form-data">
            @csrf
            <x-classic.required-notice />
            <table>
                @include('group-topic._fields')
                <x-classic.photo-rows kind="topic" />
            </table>
            <div class="operation">
                <ul class="moreInfo button">
                    <li><input type="submit" class="input_submit" value="{{ __('Post') }}"></li>
                    <li><a href="{{ route('group.topics.index', $group) }}">{{ __('Cancel') }}</a></li>
                </ul>
            </div>
        </form>
    </x-classic.parts>
@endsection
