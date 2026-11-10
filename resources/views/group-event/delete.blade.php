@extends('layouts.classic')

@section('title', __('Delete event'))

@section('content')
    {{-- Unlike the community module's yesNo confirmations, opCommunityTopicPlugin confirms deletion
         with the form kind: the question is the form's .block body. --}}
    <x-classic.parts id="deleteConfirmForm" name="form" :title="__('Delete event')">
        <form method="POST" action="{{ route('group.events.delete', $event) }}">
            @csrf
            {{-- OpenPNE 3 passes no body option here (no .block); the question is an OpenPNE 4 addition. --}}
            <p>{{ __('Delete :name? This cannot be undone.', ['name' => $event->name]) }}</p>
            <div class="operation">
                <ul class="moreInfo button">
                    <li><input type="submit" class="input_submit" value="{{ __('Delete') }}"></li>
                </ul>
            </div>
        </form>
    </x-classic.parts>
    <x-classic.history-back :fallback="route('group.events.show', $event)" />
@endsection
