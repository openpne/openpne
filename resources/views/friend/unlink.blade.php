@extends('layouts.classic')

@section('title', __('Remove %friend%'))

@section('content')
    {{-- OpenPNE 3 unlinkInput.php's yesNo kind: a .block statement over one li per answer. Its "no"
         was a second form; here it stays the link every other OpenPNE 4 confirm page uses. --}}
    <x-classic.parts id="unlinkConfirmForm" name="yesNo" :title="__('Remove %friend%')">
        <div class="block">{{ __('Remove :name from your %friends%?', ['name' => $target->name]) }}</div>
        <div class="operation">
            <ul class="moreInfo button">
                <li>
                    <form method="POST" action="{{ route('friend.unlink.submit', $target) }}">
                        @csrf
                        <input type="submit" class="input_submit" value="{{ __('Remove %friend%') }}">
                    </form>
                </li>
                <li><a href="{{ route('friend.list') }}">{{ __('Cancel') }}</a></li>
            </ul>
        </div>
    </x-classic.parts>
@endsection
