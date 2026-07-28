@extends('layouts.classic')

@section('title', __('Remove %friend%'))

{{-- The heading is a sentence with the member's name linked inside it, so it is echoed raw; the
     name is escaped here and the sentence itself comes from the catalog. --}}
@php($nameLink = '<a href="'.e(route('member.profile.show', $target)).'">'.e($target->name).'</a>')

@section('content')
    {{-- OpenPNE 3 unlinkInput.php's yesNo kind: the question is the heading, with one li per answer
         and no body. Its "no" was a second form; here it stays the link every other OpenPNE 4
         confirm page uses. --}}
    <x-classic.parts id="unlinkConfirmForm" name="yesNo">
        <x-slot:heading>
            <h3>{!! __('Do you delete :name from %my_friend%?', ['name' => $nameLink]) !!}</h3>
        </x-slot:heading>
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
