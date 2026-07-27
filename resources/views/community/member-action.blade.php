@extends('layouts.classic')

@section('title', $title)

@section('content')
    {{-- One blade for four confirmations, so the kind and id come from the controller: OpenPNE 3
         asked the drop / demote questions with the yesNo kind (statement, then one li per answer)
         and the appoint / take-over ones with the form kind (the question is the form's .block). --}}
    <x-classic.parts :id="$boxId" :name="$boxKind" :title="$title">
        @if ($boxKind === 'yesNo')
            <div class="block">{{ $message }}</div>
            <div class="operation">
                <ul class="moreInfo button">
                    <li>
                        <form method="POST" action="{{ $actionUrl }}">
                            @csrf
                            <input type="hidden" name="id" value="{{ $community->getKey() }}">
                            <input type="hidden" name="member_id" value="{{ $target->getKey() }}">
                            <input type="submit" class="input_submit" value="{{ $submitLabel }}">
                        </form>
                    </li>
                    <li><a href="{{ route('community.members.manage', $community) }}">{{ __('Cancel') }}</a></li>
                </ul>
            </div>
        @else
            <form method="POST" action="{{ $actionUrl }}">
                @csrf
                <input type="hidden" name="id" value="{{ $community->getKey() }}">
                <input type="hidden" name="member_id" value="{{ $target->getKey() }}">
                {{-- OpenPNE 3's appoint/take-over forms pass no body option (no .block); the
                     question is an OpenPNE 4 addition. --}}
                <p>{{ $message }}</p>
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ $submitLabel }}"></li>
                        <li><a href="{{ route('community.members.manage', $community) }}">{{ __('Cancel') }}</a></li>
                    </ul>
                </div>
            </form>
        @endif
    </x-classic.parts>
@endsection
