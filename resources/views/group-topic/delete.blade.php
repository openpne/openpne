@extends('layouts.classic')

@section('title', __('Delete %topic%'))

@section('content')
    {{-- Unlike the community module's yesNo confirmations, opCommunityTopicPlugin confirms deletion
         with the form kind: the question is the form's .block body. --}}
    <x-classic.parts id="deleteConfirmForm" name="form" :title="__('Delete %topic%')">
        <form method="POST" action="{{ route('group.topics.delete', $topic) }}">
            @csrf
            {{-- OpenPNE 3 passes no body option here (no .block); the question is an OpenPNE 4 addition. --}}
            <p>{{ __('Delete :name? This cannot be undone.', ['name' => $topic->name]) }}</p>
            <div class="operation">
                <ul class="moreInfo button">
                    <li><input type="submit" class="input_submit" value="{{ __('Delete') }}"></li>
                    <li><a href="{{ route('group.topics.show', $topic) }}">{{ __('Cancel') }}</a></li>
                </ul>
            </div>
        </form>
    </x-classic.parts>
@endsection
