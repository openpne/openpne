@extends('layouts.classic')

@section('title', __('Password Recovery'))

@section('content')
    {{-- A single mail-address form. The "we have emailed your reset link" status is flashed to
         session('status'), rendered by the shell. --}}
    <x-classic.parts id="passwordRecovery" name="form" :title="__('Password Recovery')">
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
    </x-classic.parts>
@endsection
