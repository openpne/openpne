@extends('layouts.classic')

@section('title', __('Register'))

@section('content')
    {{-- OpenPNE 3 opAuthMailAddress/requestRegisterURL: enter an address, a registration link is
         mailed. The "sent" confirmation is a separate screen (register.sent). --}}
    <div class="dparts" id="requestRegisterURL">
        <div class="partsHeading"><h3>{{ __('Register') }}</h3></div>
        <div class="parts">
            <p>{{ __('Please input your e-mail address. A registration link for :app will be sent to it.', ['app' => config('app.name')]) }}</p>
            <form method="POST" action="{{ route('register.request') }}">
                @csrf
                {{-- Honeypot: off-screen and not announced, so a person never fills it; a bot that
                     does has its submit silently dropped (SpamTrap). --}}
                <div aria-hidden="true" style="position:absolute; left:-9999px;">
                    <input type="text" name="{{ $honeypot }}" value="" tabindex="-1" autocomplete="off">
                </div>
                <table>
                    <tr>
                        <th><label for="register_email">{{ __('Mail Address') }}</label></th>
                        <td><input type="email" id="register_email" name="email" value="{{ old('email') }}"
                                   class="input_text" autocomplete="email" autofocus required>
                            @error('email')<p class="error" role="alert">{{ $message }}</p>@enderror</td>
                    </tr>
                </table>
                <div class="operation">
                    <ul class="moreInfo button">
                        <li><input type="submit" class="input_submit" value="{{ __('Send') }}"></li>
                    </ul>
                </div>
            </form>
            <p class="loginLink"><a href="{{ route('login') }}">{{ __('Back to login') }}</a></p>
        </div>
    </div>
@endsection
