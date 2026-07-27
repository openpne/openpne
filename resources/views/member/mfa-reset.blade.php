@extends('layouts.classic')

@section('title', __('Reset two-factor authentication'))

@section('content')
    {{-- Token-gated landing for the admin-issued reset link (reachable logged-in or out, like
         email-change-confirm). The reset is the POST below, not this GET render, so a mail scanner /
         prefetch cannot consume the token. The password turns the factor off; the token page never
         shows the account's address or name. --}}
    <x-classic.parts id="member_config_mfa_reset" name="form" :title="__('Reset two-factor authentication')">
        <form method="POST" action="{{ route('member.mfa.reset.submit', ['token' => $token]) }}">
            @csrf
            <div class="block">{{ __('Enter your account password to turn off two-factor authentication. You will then sign in with your password alone and can set it up again.') }}</div>
            <table>
                <tr>
                    <th><label for="mfa_reset_password">{{ __('Password') }}</label></th>
                    <td><input type="password" id="mfa_reset_password" name="password"
                               class="input_text" autocomplete="current-password" autofocus required>
                        @error('password')<p class="error" role="alert">{{ $message }}</p>@enderror</td>
                </tr>
            </table>
            <div class="operation">
                <ul class="moreInfo button">
                    <li><input type="submit" class="input_submit" value="{{ __('Reset two-factor authentication') }}"></li>
                </ul>
            </div>
        </form>
    </x-classic.parts>
@endsection
