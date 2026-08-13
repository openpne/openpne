@extends('layouts.classic')

@section('title', __('Delete %community%'))

@section('content')
    {{-- OpenPNE 3 deleteSuccess.php's yesNo kind: a .block statement over one li per answer. Its
         "no" was a second form; here it stays the link every other OpenPNE 4 confirm page uses. --}}
    <x-classic.parts id="deleteConfirmForm" name="yesNo" :title="__('Delete %community%')">
        <div class="block">{{ __('Delete :name? This cannot be undone.', ['name' => $group->name]) }}</div>
        <div class="operation">
            <ul class="moreInfo button">
                <li>
                    <form method="POST" action="{{ route('group.delete', $group) }}">
                        @csrf
                        <input type="submit" class="input_submit" value="{{ __('Delete %community%') }}">
                    </form>
                </li>
                <li><a href="{{ route('group.show', $group) }}">{{ __('Cancel') }}</a></li>
            </ul>
        </div>
    </x-classic.parts>
@endsection
