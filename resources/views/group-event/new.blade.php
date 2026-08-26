@extends('layouts.classic')

@section('title', __('Post a new event'))

@section('content')
    {{-- OpenPNE 3 names the create and edit boxes alike (formCommunityEvent). --}}
    <x-classic.parts id="formCommunityEvent" name="form" :title="__('Post a new event')">
        <form method="POST" action="{{ route('group.events.store', $group) }}" enctype="multipart/form-data">
            @csrf
            <x-classic.required-notice />
            <table>
                @include('group-event._fields')
                @include('group-event._image_fields')
            </table>
            <div class="operation">
                <ul class="moreInfo button">
                    <li><input type="submit" class="input_submit" value="{{ __('Post') }}"></li>
                    <li><a href="{{ route('group.events.index', $group) }}">{{ __('Cancel') }}</a></li>
                </ul>
            </div>
        </form>
    </x-classic.parts>
@endsection
