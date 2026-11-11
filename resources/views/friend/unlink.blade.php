@extends('layouts.classic')

@section('title', __('Remove %friend%'))

{{-- The heading is echoed raw to carry the name's link, so the sentence is escaped first: it holds a term an administrator typed. --}}
@php($nameLink = '<a href="'.e(route('member.profile.show', $target)).'">'.e($target->name).'</a>')
@php($question = str_replace(':name', $nameLink, e(__('Do you delete :name from %my_friend%?'))))

@section('content')
    {{-- OpenPNE 3 unlinkInput.php's yesNo kind: the question is the heading, with one li per answer
         and no body. Its "no" was a second form; here it stays the link every other OpenPNE 4
         confirm page uses. --}}
    <x-classic.parts id="unlinkConfirmForm" name="yesNo">
        <x-slot:heading>
            <h3>{!! $question !!}</h3>
        </x-slot:heading>
        <div class="operation">
            <ul class="moreInfo button">
                <li>
                    <form method="POST" action="{{ route('friend.unlink.submit', $target) }}">
                        @csrf
                        <input type="submit" class="input_submit" value="{{ __('Remove %friend%') }}">
                    </form>
                </li>
                {{-- unlinkInput.php's no_url: back to the manage roster this confirm came from. --}}
                <li><a href="{{ route('friend.manage') }}">{{ __('Cancel') }}</a></li>
            </ul>
        </div>
    </x-classic.parts>
@endsection
