@extends('layouts.classic')

@section('title', __('Delete %topic%'))

@section('content')
    {{-- Unlike the community module's yesNo confirmations, opCommunityTopicPlugin confirms deletion
         with the form kind: the question is the form's .block body. --}}
    <x-classic.parts id="deleteConfirmForm" name="form" :title="__('Delete %topic%')">
        <form method="POST" action="{{ route('communityTopic.delete', $topic) }}">
            @csrf
            <div class="block">{{ __('Delete :name? This cannot be undone.', ['name' => $topic->name]) }}</div>
            <div class="operation">
                <ul class="moreInfo button">
                    <li><input type="submit" class="input_submit" value="{{ __('Delete') }}"></li>
                    <li><a href="{{ route('communityTopic.show', $topic) }}">{{ __('Cancel') }}</a></li>
                </ul>
            </div>
        </form>
    </x-classic.parts>
@endsection
