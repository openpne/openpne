@extends('layouts.classic')

@section('title', __('Password Recovery'))

@section('content')
    {{-- OpenPNE 3 opAuthMailAddress/passwordRecoveryComplete: enter a new password. The email is
         read-only (carried from the reset link); the token is hidden. --}}
    <div class="dparts" id="passwordRecoveryComplete">
        <div class="partsHeading"><h3>{{ __('Password Recovery') }}</h3></div>
        <div class="parts">
            <p>{{ __('Please input your new password.') }}</p>
            <form method="POST" action="{{ route('password.update') }}">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <table>
                    <tr>
                        <th><label for="reset_email">{{ __('Mail Address') }}</label></th>
                        <td><input type="email" id="reset_email" name="email" value="{{ old('email', $email) }}"
                                   class="input_text" autocomplete="email" readonly>
                            @error('email')<p class="error" role="alert">{{ $message }}</p>@enderror</td>
                    </tr>
                    <tr>
                        <th><label for="reset_password">{{ __('New password') }}</label></th>
                        <td><input type="password" id="reset_password" name="password"
                                   class="input_text" autocomplete="new-password" autofocus required>
                            @error('password')<p class="error" role="alert">{{ $message }}</p>@enderror</td>
                    </tr>
                    <tr>
                        <th><label for="reset_password_confirmation">{{ __('Confirm password') }}</label></th>
                        <td><input type="password" id="reset_password_confirmation" name="password_confirmation"
                                   class="input_text" autocomplete="new-password" required></td>
                    </tr>
                </table>
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('Reset Password') }}"></li>
                    </ul>
                </div>
            </form>
        </div>
    </div>
@endsection
