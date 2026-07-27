@extends('layouts.classic')

@section('title', __('Cancel email change'))

@section('content')
    {{-- Token-gated landing for the emailed cancel link (reachable logged-in or out, like the confirm
         page). The cancellation is the POST below, not this GET render, so a mail scanner / prefetch
         cannot void the change. --}}
    <x-classic.parts id="member_config_email_cancel" name="box" :title="__('Cancel email change')">
        <div class="block">
            <p>{{ __('Cancel the pending change of your email address to :email?', ['email' => $newEmail]) }}</p>
            <form method="POST" action="{{ route('member.config.email.cancel.submit', ['token' => $token]) }}">
                @csrf
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('Cancel email change') }}"></li>
                    </ul>
                </div>
            </form>
        </div>
    </x-classic.parts>
@endsection
