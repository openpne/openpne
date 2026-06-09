@extends('layouts.classic')

@section('title', __('Register'))

@section('content')
    {{-- OpenPNE 3 member/registerInput: the token-gated account form. The address is fixed by the
         mailed token (shown, not editable); name + password + the registration profile fields are
         entered here. --}}
    <div class="dparts form" id="member_registerInput">
        <div class="partsHeading"><h3>{{ __('Register') }}</h3></div>
        <div class="parts">
            <form method="POST" action="{{ route('register.complete', ['token' => $token]) }}">
                @csrf
                <table>
                    <tr>
                        <th>{{ __('Mail Address') }}</th>
                        <td>{{ $email }}</td>
                    </tr>
                    <tr>
                        <th><label for="register_name">{{ __('%nickname%') }}</label></th>
                        <td>
                            <input type="text" class="input_text" id="register_name" name="name" value="{{ old('name') }}" maxlength="255" autofocus required>
                            @error('name')<p class="error">{{ $message }}</p>@enderror
                        </td>
                    </tr>
                    <tr>
                        <th><label for="register_password">{{ __('Password') }}</label></th>
                        <td>
                            <input type="password" class="input_text" id="register_password" name="password" autocomplete="new-password" required>
                            @error('password')<p class="error">{{ $message }}</p>@enderror
                        </td>
                    </tr>
                    <tr>
                        <th><label for="register_password_confirmation">{{ __('Confirm password') }}</label></th>
                        <td>
                            <input type="password" class="input_text" id="register_password_confirmation" name="password_confirmation" autocomplete="new-password" required>
                        </td>
                    </tr>

                    @include('profile._fields')
                </table>

                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('Register') }}"></li>
                    </ul>
                </div>
            </form>
        </div>
    </div>
@endsection
