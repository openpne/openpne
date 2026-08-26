@extends('layouts.classic')

@section('title', __('Delete %community%'))

@section('content')
    {{-- OpenPNE 3 deleteSuccess.php's yesNo kind (_partsYesNo.php): a .block statement over one
         li per answer, each answer a form with a submit — the "no" too, sent back to the home. --}}
    <x-classic.parts id="deleteConfirmForm" name="yesNo" :title="__('Delete %community%')">
        <div class="block">{{ __('Delete :name? This cannot be undone.', ['name' => $group->name]) }}</div>
        <div class="operation">
            <ul class="moreInfo button">
                <li>
                    <form method="POST" action="{{ route('group.delete', $group) }}">
                        @csrf
                        <input type="submit" class="input_submit" value="{{ __('Yes') }}">
                    </form>
                </li>
                <li>
                    <form method="get" action="{{ route('group.show', $group) }}">
                        <input type="submit" class="input_submit" value="{{ __('No') }}">
                    </form>
                </li>
            </ul>
        </div>
    </x-classic.parts>
@endsection
