@extends('layouts.classic')

@use('App\Support\Surface')
@use('App\Features\Member\MemberConfigCategory')

@section('title', __('Settings'))

{{-- OpenPNE 3 member/config is paginated by ?category= (LayoutB): the category nav fills the
     sidemenu, the active category's form the center, or a "pick one" landing when none is selected. --}}
@section('sidemenu')
    <x-member.config-sidemenu :current="$category" :age-available="$ageAvailable" />
@endsection

@section('content')
    @switch($category)
        @case(MemberConfigCategory::Diary)
            {{-- Diary default audience (member_preferences[diary_default_visibility]). --}}
            <div class="dparts form" id="member_config_diary">
                <div class="partsHeading"><h3>{{ __('Diary') }}</h3></div>
                <div class="parts">
                    <form method="POST" action="{{ route('member.config.diary') }}">
                        @csrf
                        <table>
                            <tr>
                                <th><label for="diary_default_visibility">{{ __('Default audience for new diaries') }}</label></th>
                                <td>
                                    <select id="diary_default_visibility" name="diary_default_visibility">
                                        @foreach ($diaryOptions as $option)
                                            <option value="{{ $option->value }}" @selected(old('diary_default_visibility', $diaryDefault->value) == $option->value)>{{ __($option->label()) }}</option>
                                        @endforeach
                                    </select>
                                    @error('diary_default_visibility')<p class="error">{{ $message }}</p>@enderror
                                </td>
                            </tr>
                        </table>
                        <div class="operation">
                            <ul class="moreInfo button">
                                <li><input type="submit" class="input_submit" value="{{ __('Save') }}"></li>
                            </ul>
                        </div>
                    </form>
                </div>
            </div>
            @break

        @case(MemberConfigCategory::PublicFlag)
            {{-- Age visibility (member_preferences[age_visibility]); Open is offered only while web-public age is on. --}}
            <div class="dparts form" id="member_config_age">
                <div class="partsHeading"><h3>{{ __('Age') }}</h3></div>
                <div class="parts">
                    <form method="POST" action="{{ route('member.config.age') }}">
                        @csrf
                        <table>
                            <tr>
                                <th><label for="age_visibility">{{ __('Who can see your age') }}</label></th>
                                <td>
                                    <select id="age_visibility" name="age_visibility">
                                        @foreach ($ageOptions as $option)
                                            <option value="{{ $option->value }}" @selected(old('age_visibility', $ageDefault->value) == $option->value)>{{ __($option->label()) }}</option>
                                        @endforeach
                                    </select>
                                    @error('age_visibility')<p class="error">{{ $message }}</p>@enderror
                                </td>
                            </tr>
                        </table>
                        <div class="operation">
                            <ul class="moreInfo button">
                                <li><input type="submit" class="input_submit" value="{{ __('Save') }}"></li>
                            </ul>
                        </div>
                    </form>
                </div>
            </div>
            @break

        @case(MemberConfigCategory::Language)
            {{-- Language: reuses the shared locale switch endpoint (durable members.locale write). --}}
            <div class="dparts form" id="member_config_language">
                <div class="partsHeading"><h3>{{ __('Language') }}</h3></div>
                <div class="parts">
                    <form method="POST" action="{{ route('locale.switch') }}">
                        @csrf
                        <table>
                            <tr>
                                <th><label for="locale">{{ __('Language') }}</label></th>
                                <td>
                                    <select id="locale" name="locale">
                                        <option value="ja" @selected($locale === 'ja')>日本語</option>
                                        <option value="en" @selected($locale === 'en')>English</option>
                                    </select>
                                </td>
                            </tr>
                        </table>
                        <div class="operation">
                            <ul class="moreInfo button">
                                <li><input type="submit" class="input_submit" value="{{ __('Save') }}"></li>
                            </ul>
                        </div>
                    </form>
                </div>
            </div>
            @break

        @case(MemberConfigCategory::General)
            {{-- Display surface (member_preferences[preferred_surface]); binary, preselected to the member's
                 current surface. Saving the current one is a no-op server-side, so it never pins. OpenPNE
                 4-native setting under OpenPNE 3's "general" catch-all category. --}}
            <div class="dparts form" id="member_config_surface">
                <div class="partsHeading"><h3>{{ __('Display') }}</h3></div>
                <div class="parts">
                    <form method="POST" action="{{ route('member.config.surface') }}">
                        @csrf
                        @foreach ([Surface::Classic, Surface::Modern] as $option)
                            <p>
                                <label>
                                    <input type="radio" name="preferred_surface" value="{{ $option->value }}" @checked($currentSurface === $option)>
                                    {{ __($option->label()) }} — {{ __($option->description()) }}
                                </label>
                            </p>
                        @endforeach
                        @error('preferred_surface')<p class="error">{{ $message }}</p>@enderror
                        <div class="operation">
                            <ul class="moreInfo button">
                                <li><input type="submit" class="input_submit" value="{{ __('Save') }}"></li>
                            </ul>
                        </div>
                    </form>
                </div>
            </div>
            @break

        @case(MemberConfigCategory::Password)
            {{-- In-session password change: re-auth with the current password, new password entered twice. --}}
            <div class="dparts form" id="member_config_password">
                <div class="partsHeading"><h3>{{ __('Password') }}</h3></div>
                <div class="parts">
                    <form method="POST" action="{{ route('member.config.password') }}">
                        @csrf
                        <table>
                            <tr>
                                <th><label for="current_password">{{ __('Current password') }}</label></th>
                                <td>
                                    <input type="password" id="current_password" name="current_password" autocomplete="current-password">
                                    @error('current_password')<p class="error">{{ $message }}</p>@enderror
                                </td>
                            </tr>
                            <tr>
                                <th><label for="password">{{ __('New password') }}</label></th>
                                <td>
                                    <input type="password" id="password" name="password" autocomplete="new-password">
                                    @error('password')<p class="error">{{ $message }}</p>@enderror
                                </td>
                            </tr>
                            <tr>
                                <th><label for="password_confirmation">{{ __('New password (confirm)') }}</label></th>
                                <td>
                                    <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password">
                                </td>
                            </tr>
                        </table>
                        <div class="operation">
                            <ul class="moreInfo button">
                                <li><input type="submit" class="input_submit" value="{{ __('Save') }}"></li>
                            </ul>
                        </div>
                    </form>
                </div>
            </div>
            @break

        @case(MemberConfigCategory::Mfa)
            {{-- Two-factor authentication: disabled → pending (QR + code confirm) → enabled.
                 The password is asked once at enable (re-auth window); disabling a live factor and
                 regenerating codes re-ask it, cancelling an inert pending set-up does not. --}}
            <div class="dparts form" id="member_config_mfa">
                <div class="partsHeading"><h3>{{ __('Two-factor authentication') }}</h3></div>
                <div class="parts">
                    @if ($mfa['state'] === 'disabled')
                        <p>{{ __('To continue, first confirm it is you.') }}</p>
                        <form method="POST" action="{{ route('member.config.mfa.enable') }}">
                            @csrf
                            <table>
                                <tr>
                                    <th><label for="mfa_current_password">{{ __('Current password') }}</label></th>
                                    <td>
                                        <input type="password" id="mfa_current_password" name="current_password" autocomplete="current-password">
                                        @error('current_password')<p class="error" role="alert">{{ $message }}</p>@enderror
                                    </td>
                                </tr>
                            </table>
                            <div class="operation">
                                <ul class="moreInfo button">
                                    <li><input type="submit" class="input_submit" value="{{ __('Set up two-factor authentication') }}"></li>
                                </ul>
                            </div>
                        </form>
                    @elseif ($mfa['state'] === 'pending')
                        <p>{{ __('You need an authenticator app that generates a one-time code at login. Search your device\'s app store for "authenticator" and install one.') }}</p>
                        <p>{{ __('Scan the following QR code with your authenticator app:') }}</p>
                        <img src="{{ $mfa['qrCode'] }}" alt="{{ __('QR code for your authenticator app') }}" width="192" height="192">
                        <p>{{ __('Or enter the following code manually:') }} <code>{{ $mfa['secret'] }}</code></p>
                        <form method="POST" action="{{ route('member.config.mfa.confirm') }}">
                            @csrf
                            <table>
                                <tr>
                                    <th><label for="mfa_code">{{ __('Authentication code') }}</label></th>
                                    <td>
                                        <input type="text" id="mfa_code" name="code" class="input_text"
                                               inputmode="numeric" autocomplete="one-time-code">
                                        @error('code')<p class="error" role="alert">{{ $message }}</p>@enderror
                                    </td>
                                </tr>
                                @if ($mfa['requiresPassword'])
                                    <tr>
                                        <th><label for="mfa_current_password">{{ __('Current password') }}</label></th>
                                        <td>
                                            <input type="password" id="mfa_current_password" name="current_password" autocomplete="current-password">
                                            <p>{{ __('Some time has passed since you started, so please confirm it is you: enter your current password as well.') }}</p>
                                            @error('current_password')<p class="error" role="alert">{{ $message }}</p>@enderror
                                        </td>
                                    </tr>
                                @endif
                            </table>
                            <div class="operation">
                                <ul class="moreInfo button">
                                    <li><input type="submit" class="input_submit" value="{{ __('Confirm') }}"></li>
                                </ul>
                            </div>
                        </form>
                        {{-- Cancelling clears the inert pending secret (no password: it gates nothing);
                             setting up again issues a fresh QR. --}}
                        <form method="POST" action="{{ route('member.config.mfa.disable') }}">
                            @csrf
                            <div class="operation">
                                <ul class="moreInfo button">
                                    <li><input type="submit" class="input_submit" value="{{ __('Cancel set-up') }}"></li>
                                </ul>
                            </div>
                        </form>
                    @else
                        @php($submittedForm = old('_mfa_form', 'regenerate'))
                        <p>{{ __('Two-factor authentication is enabled.') }}</p>
                        @isset($mfa['recoveryCodes'])
                            {{-- Shown once, right after confirm/regenerate minted them. --}}
                            <p class="error">{{ __('Store these codes somewhere safe. Each can be used once if you lose your authenticator.') }}</p>
                            <ul>
                                @foreach ($mfa['recoveryCodes'] as $code)
                                    <li><code>{{ $code }}</code></li>
                                @endforeach
                            </ul>
                        @else
                            <p>{{ __('Unused recovery codes') }}: {{ $mfa['recoveryCodesCount'] }}</p>
                        @endisset
                        <form method="POST" action="{{ route('member.config.mfa.recovery') }}">
                            @csrf
                            <input type="hidden" name="_mfa_form" value="regenerate">
                            <p>{{ __('Regenerating replaces every unused recovery code with a fresh set.') }}</p>
                            <table>
                                <tr>
                                    <th><label for="mfa_regen_password">{{ __('Current password') }}</label></th>
                                    <td>
                                        <input type="password" id="mfa_regen_password" name="current_password" autocomplete="current-password">
                                        @if ($submittedForm === 'regenerate')
                                            @error('current_password')<p class="error" role="alert">{{ $message }}</p>@enderror
                                        @endif
                                    </td>
                                </tr>
                            </table>
                            <div class="operation">
                                <ul class="moreInfo button">
                                    <li><input type="submit" class="input_submit" value="{{ __('Regenerate recovery codes') }}"></li>
                                </ul>
                            </div>
                        </form>
                        <details {{ $submittedForm === 'disable' && $errors->any() ? 'open' : '' }}>
                            <summary>{{ __('Disable two-factor authentication') }}</summary>
                            <form method="POST" action="{{ route('member.config.mfa.disable') }}">
                                @csrf
                                <input type="hidden" name="_mfa_form" value="disable">
                                <p>{{ __('Your password alone will sign you in again.') }}</p>
                                <table>
                                    <tr>
                                        <th><label for="mfa_disable_password">{{ __('Current password') }}</label></th>
                                        <td>
                                            <input type="password" id="mfa_disable_password" name="current_password" autocomplete="current-password">
                                            @if ($submittedForm === 'disable')
                                                @error('current_password')<p class="error" role="alert">{{ $message }}</p>@enderror
                                            @endif
                                        </td>
                                    </tr>
                                </table>
                                <div class="operation">
                                    <ul class="moreInfo button">
                                        <li><input type="submit" class="input_submit" value="{{ __('Disable two-factor authentication') }}"></li>
                                    </ul>
                                </div>
                            </form>
                        </details>
                    @endif
                </div>
            </div>
            @break

        @case(MemberConfigCategory::Email)
            {{-- Email-address change: re-auth with the current password; a confirmation link is mailed
                 to the new address and the change commits only when that link is confirmed. --}}
            <div class="dparts form" id="member_config_email">
                <div class="partsHeading"><h3>{{ __('Email address') }}</h3></div>
                <div class="parts">
                    <form method="POST" action="{{ route('member.config.email') }}">
                        @csrf
                        <table>
                            <tr>
                                <th>{{ __('Current email address') }}</th>
                                <td>{{ $email }}</td>
                            </tr>
                            <tr>
                                <th><label for="new_email">{{ __('New email address') }}</label></th>
                                <td>
                                    <input type="email" id="new_email" name="new_email" value="{{ old('new_email') }}">
                                    @error('new_email')<p class="error">{{ $message }}</p>@enderror
                                </td>
                            </tr>
                            <tr>
                                <th><label for="email_password">{{ __('Current password') }}</label></th>
                                <td>
                                    <input type="password" id="email_password" name="password" autocomplete="current-password">
                                    @error('password')<p class="error">{{ $message }}</p>@enderror
                                </td>
                            </tr>
                        </table>
                        <p>{{ __('A confirmation link will be sent to the new address. The change takes effect once you open it.') }}</p>
                        <div class="operation">
                            <ul class="moreInfo button">
                                <li><input type="submit" class="input_submit" value="{{ __('Send confirmation') }}"></li>
                            </ul>
                        </div>
                    </form>
                </div>
            </div>
            @break

        @case(MemberConfigCategory::Withdrawal)
            {{-- Permanent account deletion: re-auth with the current password + an explicit confirm. --}}
            <div class="dparts form" id="member_config_withdrawal">
                <div class="partsHeading"><h3>{{ __('Account withdrawal') }}</h3></div>
                <div class="parts">
                    <form method="POST" action="{{ route('member.config.withdrawal') }}">
                        @csrf
                        <p>{{ __('Withdrawing permanently deletes your account and cannot be undone.') }}</p>
                        <table>
                            <tr>
                                <th><label for="withdraw_password">{{ __('Current password') }}</label></th>
                                <td>
                                    <input type="password" id="withdraw_password" name="password" autocomplete="current-password">
                                    @error('password')<p class="error">{{ $message }}</p>@enderror
                                </td>
                            </tr>
                            <tr>
                                <th></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="confirm" value="1">
                                        {{ __('Yes, delete my account.') }}
                                    </label>
                                    @error('confirm')<p class="error">{{ $message }}</p>@enderror
                                </td>
                            </tr>
                        </table>
                        <div class="operation">
                            <ul class="moreInfo button">
                                <li><input type="submit" class="input_submit" value="{{ __('Withdraw from this site') }}"></li>
                            </ul>
                        </div>
                    </form>
                </div>
            </div>
            @break

        @default
            {{-- OpenPNE 3 landing (configInformation): no category selected, pick one from the nav.
                 id is an OpenPNE 4-side hook, not an OpenPNE 3 parity claim. --}}
            <div class="dparts" id="member_config_index">
                <div class="partsHeading"><h3>{{ __('Change Settings') }}</h3></div>
                <div class="parts">
                    <p>{{ __('Please select the item that wants to be set from the menu.') }}</p>
                </div>
            </div>
    @endswitch
@endsection
