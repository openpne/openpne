@extends('layouts.classic')

@section('title', __('Confirm email change'))

@section('content')
    {{-- Token-gated landing for the emailed confirmation link (reachable logged-in or out, like
         register-complete). The change is the POST below, not this GET render, so a mail scanner /
         prefetch cannot consume the token. --}}
    <x-classic.parts id="member_config_email_confirm" name="box" :title="__('Confirm email change')">
        <div class="block">
            <p>{{ __('Confirm changing your email address to :email.', ['email' => $newEmail]) }}</p>
            <form method="POST" action="{{ route('member.config.email.confirm.submit', ['token' => $token]) }}">
                @csrf
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('Confirm email change') }}"></li>
                    </ul>
                </div>
            </form>
        </div>
    </x-classic.parts>
@endsection
