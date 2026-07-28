{{-- The login form with its ALTCHA / registration / error behaviour. Shared by the fixed login
     page and the loginForm gadget. The box id is the fixed `loginForm` (one form per page), not
     a gadget-scoped id.

     OpenPNE 3 renders this one box outside the parts frame — a bare `div.loginForm` per auth mode,
     which its themes reach by id instead. The inner div keeps that `.loginForm` class hook (the
     skin styles its cells, inputs and .password_query through it); the frame around it is the
     OpenPNE 4 titled box, hence a kind-less dparts. --}}
<x-classic.parts id="loginForm" :title="__('Login')">
    <div class="loginForm">
        <form method="POST" action="{{ route('login') }}">
            @csrf
            <table>
                <tr>
                    <th><label for="login_email">{{ __('Mail Address') }}</label></th>
                    <td><input type="email" id="login_email" name="email" value="{{ old('email') }}"
                               class="input_text" autocomplete="email" autofocus required></td>
                </tr>
                <tr>
                    <th><label for="login_password">{{ __('Password') }}</label></th>
                    <td><input type="password" id="login_password" name="password"
                               class="input_text" autocomplete="current-password" required></td>
                </tr>
                <tr>
                    <td colspan="2">
                        {{-- Fortify flashes the failed-login message on the email key. --}}
                        @error('email')<p class="error" role="alert">{{ $message }}</p>@enderror
                        @if ($captchaRequired ?? false)
                            {{-- Shown after repeated failures from this IP (ALTCHA proof-of-work). --}}
                            <altcha-widget challenge="{{ $challengeUrl }}" name="altcha"></altcha-widget>
                            @error('altcha')<p class="error" role="alert">{{ $message }}</p>@enderror
                        @endif
                        <p class="password_query">
                            <a href="{{ route('password.request') }}">{{ __('Can not access your account?') }}</a>
                        </p>
                        <input type="submit" class="input_submit" value="{{ __('Login') }}">
                    </td>
                </tr>
            </table>
        </form>
        @if ($registrationOpen ?? false)
            <p class="registerLink"><a href="{{ route('register') }}">{{ __('Register') }}</a></p>
        @endif
    </div>
</x-classic.parts>

@if ($captchaRequired ?? false)
    {{-- Registers <altcha-widget>. Kept outside the form table so the production build's
         modulepreload <link> is not foster-parented out of the table by the HTML parser. --}}
    @vite('resources/js/altcha.ts')
@endif
