@extends('layouts.classic')

@section('title', __('Confirmation code'))

@section('content')
    <x-classic.parts id="loginCode" name="form" :title="__('Confirmation code')">
        <form method="POST" action="{{ route('login.code.store') }}">
            @csrf
            <table>
                <tr>
                    <th><label for="login_code">{{ __('Confirmation code') }}</label></th>
                    <td><input type="text" id="login_code" name="code" class="input_text"
                               inputmode="numeric" autocomplete="one-time-code" autofocus required>
                        <p>{{ __('Enter the six-digit code we sent to your email address.') }}</p>
                        @error('code')<p class="error" role="alert">{{ $message }}</p>@enderror</td>
                </tr>
            </table>
            <div class="operation">
                <ul class="moreInfo button">
                    <li><input type="submit" class="input_submit" value="{{ __('Sign in') }}"></li>
                </ul>
            </div>
        </form>

        <form method="POST" action="{{ route('login.code.resend') }}">
            @csrf
            <div class="operation">
                <ul class="moreInfo button">
                    <li><input type="submit" class="input_submit" value="{{ __('Send a new code') }}"></li>
                </ul>
            </div>
        </form>
    </x-classic.parts>
@endsection
