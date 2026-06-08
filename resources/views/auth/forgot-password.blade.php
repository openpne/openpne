@extends('layouts.classic')

@section('title', __('Password Recovery'))

@section('content')
    {{-- OpenPNE 3 opAuthMailAddress/passwordRecovery: a single mail-address form. The "we have
         emailed your reset link" status is flashed to session('status'), rendered by the shell. --}}
    <div class="dparts" id="passwordRecovery">
        <div class="partsHeading"><h3>{{ __('Password Recovery') }}</h3></div>
        <div class="parts">
            <form method="POST" action="{{ route('password.email') }}">
                @csrf
                <table>
                    <tr>
                        <th><label for="recovery_email">{{ __('Mail Address') }}</label></th>
                        <td><input type="email" id="recovery_email" name="email" value="{{ old('email') }}"
                                   class="input_text" autocomplete="email" autofocus required>
                            @error('email')<p class="error" role="alert">{{ $message }}</p>@enderror</td>
                    </tr>
                </table>
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('Email password reset link') }}"></li>
                    </ul>
                </div>
            </form>
            <p class="loginLink"><a href="{{ route('login') }}">{{ __('Back to login') }}</a></p>
        </div>
    </div>
@endsection
