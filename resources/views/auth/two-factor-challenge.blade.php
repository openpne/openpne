@extends('layouts.classic')

@section('title', __('Two-factor authentication'))

@section('content')
    {{-- Second login step for a member with TOTP enabled. The challenged member is carried in the
         session (login.id), so there is no identifier field; either form completes the login. --}}
    <div class="dparts" id="twoFactorChallenge">
        <div class="partsHeading"><h3>{{ __('Two-factor authentication') }}</h3></div>
        <div class="parts">
            <form method="POST" action="{{ route('two-factor.login.store') }}">
                @csrf
                <table>
                    <tr>
                        <th><label for="two_factor_code">{{ __('Authentication code') }}</label></th>
                        <td><input type="text" id="two_factor_code" name="code" class="input_text"
                                   inputmode="numeric" autocomplete="one-time-code" autofocus required>
                            <p>{{ __('Enter the six-digit code shown in your authenticator app.') }}</p>
                            @error('code')<p class="error" role="alert">{{ $message }}</p>@enderror</td>
                    </tr>
                </table>
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('Sign in') }}"></li>
                    </ul>
                </div>
            </form>

            {{-- Progressive disclosure without JS; reopened when a recovery attempt just failed so
                 the error is not hidden behind the closed summary. --}}
            <details @if ($errors->has('recovery_code')) open @endif>
                <summary>{{ __('Use a recovery code instead') }}</summary>
                <form method="POST" action="{{ route('two-factor.login.store') }}">
                    @csrf
                    <table>
                        <tr>
                            <th><label for="two_factor_recovery_code">{{ __('Recovery code') }}</label></th>
                            <td><input type="text" id="two_factor_recovery_code" name="recovery_code"
                                       class="input_text" autocomplete="off" required>
                                <p>{{ __('Each recovery code can be used once, if you no longer have your authenticator.') }}</p>
                                @error('recovery_code')<p class="error" role="alert">{{ $message }}</p>@enderror</td>
                        </tr>
                    </table>
                    <div class="operation">
                        <ul class="moreInfo button">
                            <li><input type="submit" class="input_submit" value="{{ __('Sign in') }}"></li>
                        </ul>
                    </div>
                </form>
            </details>
        </div>
    </div>
@endsection
