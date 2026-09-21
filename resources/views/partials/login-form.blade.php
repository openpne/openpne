{{-- The login form with its ALTCHA / registration / error behavior. Shared by the fixed login
     page and the loginForm gadget.

     OpenPNE 3 draws this with no parts frame at all: a bare div per auth mode
     (`#MailAddressLogin.loginForm`), no heading, the skin styling its cells, inputs and
     .password_query through the class. The frame this once added read as a foreign box next to
     every other OpenPNE 3 screen, so the bare div is what renders — id included, since it is the
     seam a site's own CSS reaches. --}}
<div id="MailAddressLogin" class="loginForm">
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
                {{-- OpenPNE 3 appends is_remember_me to the login form after the credentials, so it
                     renders as the row below the password. Fortify reads the `remember` input. --}}
                <tr>
                    <th><label for="login_remember">{{ __('Remember me') }}</label></th>
                    <td><input type="checkbox" class="input_checkbox" id="login_remember" name="remember" value="1"
                               @checked(old('remember'))></td>
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
                        {{-- Hidden until the lane below confirms WebAuthn support. --}}
                        <button type="button" class="input_submit"
                                data-passkey-login
                                data-options-url="{{ route('passkey.login-options') }}"
                                data-submit-url="{{ route('passkey.login') }}"
                                data-remember-input="login_remember"
                                data-messages="{{ json_encode([
                                    'The passkey prompt was cancelled.' => __('The passkey prompt was cancelled.'),
                                    'This browser does not support passkeys.' => __('This browser does not support passkeys.'),
                                    'Too many attempts. Please wait a moment and try again.' => __('Too many attempts. Please wait a moment and try again.'),
                                    'The passkey could not be used. Please try again.' => __('The passkey could not be used. Please try again.'),
                                ]) }}"
                                hidden>{{ __('Sign in with a passkey') }}</button>
                        <p class="error" role="alert" data-passkey-error hidden></p>
                    </td>
                </tr>
            </table>
        </form>
        @if ($registrationOpen ?? false)
            <p class="registerLink"><a href="{{ route('register') }}">{{ __('Register') }}</a></p>
        @endif
</div>

{{-- Kept outside the form table so the production build's modulepreload <link> is not
     foster-parented out of the table by the HTML parser. --}}
@vite('resources/js/passkeys-classic.ts')
@if ($captchaRequired ?? false)
    {{-- Registers <altcha-widget>. --}}
    @vite('resources/js/altcha.ts')
@endif
