@extends('layouts.classic')

@section('title', __('Post a new %topic%'))

@section('content')
    {{-- OpenPNE 3 names the create and edit boxes alike (formCommunityTopic). --}}
    <x-classic.parts id="formCommunityTopic" name="form" :title="__('Post a new %topic%')">
        <form method="POST" action="{{ route('communityTopic.store', $community) }}" enctype="multipart/form-data">
            @csrf
            <table>
                @include('community-topic._fields')
                @include('community-topic._image_fields')
            </table>
            <div class="operation">
                <ul class="moreInfo button">
                    <li><input type="submit" class="input_submit" value="{{ __('Post') }}"></li>
                    <li><a href="{{ route('communityTopic.index', $community) }}">{{ __('Cancel') }}</a></li>
                </ul>
            </div>
        </form>
    </x-classic.parts>
@endsection
