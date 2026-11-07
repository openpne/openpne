@extends('layouts.classic')

@section('title', __('Delete message'))

@section('content')
    {{-- OpenPNE 3 confirmed a timeline deletion inline in JavaScript rather than on a page, so this
         follows the box + .block shape its hand-written confirmations use (timeline/delete). --}}
    <x-classic.parts id="group_talk_delete" name="box" :title="__('Delete message')">
        <div class="block">
            <p>{{ __('Delete this message?') }}</p>
            <div class="timeline-post-body"><x-group-talk-body :message="$message" /></div>
            <form method="POST" action="{{ route('group.talk.delete', ['group' => $group, 'message' => $message]) }}">
                @csrf
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('Delete') }}"></li>
                        <li><a href="{{ route('group.talk.show', ['group' => $group, 'm' => $message->getKey()]) }}#timeline-{{ $message->getKey() }}">{{ __('Cancel') }}</a></li>
                    </ul>
                </div>
            </form>
        </div>
    </x-classic.parts>
@endsection
