@extends('layouts.classic')

@use('App\Support\Surface')
@use('App\Features\Member\MemberConfigCategory')

@section('title', __('Settings'))

{{-- Paginated by ?category=: the category nav fills the sidemenu, the active category's form
     the center, or a "pick one" landing when none is selected. Box ids follow OpenPNE 3's
     `{categoryName}Form` (a custom-CSS seam it derived from the category key); the categories
     OpenPNE 4 added keep their own id, having no OpenPNE 3 id to restore. --}}
@section('sidemenu')
    <x-member.config-sidemenu :current="$category" :age-available="$ageAvailable" />
@endsection

@section('content')
    @switch($category)
        @case(MemberConfigCategory::Diary)
            {{-- Diary default audience (member_preferences[diary_default_visibility]). --}}
            <x-classic.parts id="diaryForm" name="form" :title="__('%Diary%')">
                <form method="POST" action="{{ route('member.config.diary') }}">
                    @csrf
                    <table>
                        <tr>
                            <th><label for="diary_default_visibility">{{ __('Default audience for new %diaries%') }}</label></th>
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
            </x-classic.parts>
            @break

        @case(MemberConfigCategory::PublicFlag)
            {{-- Age visibility (member_preferences[age_visibility]); Open is offered only while web-public age is on. --}}
            <x-classic.parts id="publicFlagForm" name="form" :title="__('Age')">
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
            </x-classic.parts>
            @break

        @case(MemberConfigCategory::Language)
            {{-- Language: reuses the shared locale switch endpoint (durable members.locale write). --}}
            <x-classic.parts id="languageForm" name="form" :title="__('Language')">
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
            </x-classic.parts>
            @break

        @case(MemberConfigCategory::General)
            {{-- Display surface (member_preferences[preferred_surface]); binary, preselected to the member's
                 current surface. Saving the current one is a no-op server-side, so it never pins. --}}
            <x-classic.parts id="generalForm" name="form" :title="__('Display')">
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
            </x-classic.parts>
            @break

        @case(MemberConfigCategory::Notification)
            {{-- The notification catalog opt-ins: every wired kind × in-app/email as flat
                 checkboxes, one bulk save. A hidden 0 precedes each checkbox so an unchecked box
                 still submits an explicit false. --}}
            <x-classic.parts id="member_config_notification" name="form" :title="__('Notifications')">
                <form method="POST" action="{{ route('member.config.notifications') }}">
                    @csrf
                    <table>
                        @foreach ($notificationGroups as $group)
                            <tr><th colspan="2">{{ $group['caption'] }}</th></tr>
                            @foreach ($group['kinds'] as $kind)
                                <tr>
                                    <th>{{ $kind['caption'] }}</th>
                                    <td>
                                        <label>
                                            <input type="hidden" name="settings[{{ $kind['kind'] }}][web]" value="0">
                                            <input type="checkbox" name="settings[{{ $kind['kind'] }}][web]" value="1" @checked($kind['web'])>
                                            {{ __('In-app notifications') }}
                                        </label>
                                        <label>
                                            <input type="hidden" name="settings[{{ $kind['kind'] }}][mail]" value="0">
                                            <input type="checkbox" name="settings[{{ $kind['kind'] }}][mail]" value="1" @checked($kind['mail'])>
                                            {{ __('Email notifications') }}
                                        </label>
                                    </td>
                                </tr>
                            @endforeach
                        @endforeach
                    </table>
                    @error('settings')<p class="error">{{ $message }}</p>@enderror
                    <div class="operation">
                        <ul class="moreInfo button">
                            <li><input type="submit" class="input_submit" value="{{ __('Save') }}"></li>
                        </ul>
                    </div>
                </form>
            </x-classic.parts>
            @break

        @case(MemberConfigCategory::Password)
            {{-- In-session password change: re-auth with the current password, new password entered twice. --}}
            <x-classic.parts id="passwordForm" name="form" :title="__('Password')">
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
            </x-classic.parts>
            @break

        @case(MemberConfigCategory::Mfa)
            {{-- Two-factor authentication: disabled → pending (QR + code confirm) → enabled.
                 The password is asked once at enable (re-auth window); disabling a live factor and
                 regenerating codes re-ask it, cancelling an inert pending set-up does not. --}}
            @if ($mfa['state'] === 'disabled')
                <x-classic.parts id="member_config_mfa" name="form" :title="__('Two-factor authentication')">
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
                </x-classic.parts>
            @elseif ($mfa['state'] === 'pending')
                <x-classic.parts id="member_config_mfa" name="form" :title="__('Two-factor authentication')">
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
                </x-classic.parts>
            @else
                @php($submittedForm = old('_mfa_form', 'regenerate'))
                <x-classic.parts id="member_config_mfa" name="box" :title="__('Two-factor authentication')">
                    <div class="body">
                        <p>{{ __('Two-factor authentication is enabled.') }}</p>
                        @isset($mfa['recoveryCodes'])
                            {{-- Shown once, right after confirm/regenerate minted them. --}}
                            <p class="error">{{ __('These codes are shown only this once.') }}</p>
                            <p>{{ __('Save them somewhere safe now — each can be used once to sign in if you lose your authenticator.') }}</p>
                            <ul>
                                @foreach ($mfa['recoveryCodes'] as $code)
                                    <li><code>{{ $code }}</code></li>
                                @endforeach
                            </ul>
                        @else
                            <p>{{ __('Unused recovery codes') }}: {{ $mfa['recoveryCodesCount'] }}</p>
                        @endisset
                    </div>
                </x-classic.parts>
                {{-- Each management action gets its own headed box, so what a password field is
                     FOR is stated before the field itself. --}}
                <x-classic.parts id="member_config_mfa_recovery" name="form" :title="__('Regenerate recovery codes')">
                    <p>{{ __('Regenerating replaces every unused recovery code with a fresh set.') }}</p>
                    <form method="POST" action="{{ route('member.config.mfa.recovery') }}">
                        @csrf
                        <input type="hidden" name="_mfa_form" value="regenerate">
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
                            <tr>
                                <th><label for="mfa_regen_code">{{ __('Authentication code') }}</label></th>
                                <td>
                                    <input type="text" id="mfa_regen_code" name="code" class="input_text"
                                           inputmode="numeric" autocomplete="one-time-code">
                                    {{-- `code` is shared with the disable form below, so gate it on the submitted form. --}}
                                    @if ($submittedForm === 'regenerate')
                                        @error('code')<p class="error" role="alert">{{ $message }}</p>@enderror
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
                </x-classic.parts>
                <x-classic.parts id="member_config_mfa_disable" name="form" :title="__('Disable two-factor authentication')">
                    <p>{{ __('Your password alone will sign you in again.') }}</p>
                    <form method="POST" action="{{ route('member.config.mfa.disable') }}">
                        @csrf
                        <input type="hidden" name="_mfa_form" value="disable">
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
                            <tr>
                                <th><label for="mfa_disable_code">{{ __('Authentication code') }}</label></th>
                                <td>
                                    <input type="text" id="mfa_disable_code" name="code" class="input_text"
                                           inputmode="numeric" autocomplete="one-time-code">
                                    {{-- `code` is shared with the regenerate form above, so gate it on the submitted form. --}}
                                    @if ($submittedForm === 'disable')
                                        @error('code')<p class="error" role="alert">{{ $message }}</p>@enderror
                                    @endif
                                </td>
                            </tr>
                        </table>
                        {{-- Progressive disclosure without JS; a recovery code is an alternative to the TOTP
                             code above (server prefers a filled recovery_code). Reopened when a recovery
                             attempt just failed so the error is not hidden behind the closed summary. Kept
                             outside the table so the disclosure wraps its own row, not a table cell. --}}
                        <details @if ($errors->has('recovery_code')) open @endif>
                            <summary>{{ __('Use a recovery code instead') }}</summary>
                            <table>
                                <tr>
                                    <th><label for="mfa_disable_recovery_code">{{ __('Recovery code') }}</label></th>
                                    <td>
                                        <input type="text" id="mfa_disable_recovery_code" name="recovery_code"
                                               class="input_text" autocomplete="off">
                                        <p>{{ __('Each recovery code can be used once, if you no longer have your authenticator.') }}</p>
                                        @error('recovery_code')<p class="error" role="alert">{{ $message }}</p>@enderror
                                    </td>
                                </tr>
                            </table>
                        </details>
                        <div class="operation">
                            <ul class="moreInfo button">
                                <li><input type="submit" class="input_submit" value="{{ __('Disable two-factor authentication') }}"></li>
                            </ul>
                        </div>
                    </form>
                </x-classic.parts>
            @endif
            @break

        @case(MemberConfigCategory::Email)
            {{-- Email-address change: re-auth with the current password; a confirmation link is mailed
                 to the new address and the change commits only when that link is confirmed. --}}
            <x-classic.parts id="member_config_email" name="form" :title="__('Email address')">
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
            </x-classic.parts>
            @break

        @case(MemberConfigCategory::Withdrawal)
            {{-- Permanent account deletion: re-auth with the current password + an explicit confirm. --}}
            <x-classic.parts id="member_config_withdrawal" name="form" :title="__('Account withdrawal')">
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
            </x-classic.parts>
            @break

        @default
            {{-- The landing screen: no category selected, pick one from the nav. --}}
            <x-classic.parts id="configInformation" name="box" :title="__('Change Settings')">
                <div class="body">{{ __('Please select the item that wants to be set from the menu.') }}</div>
            </x-classic.parts>
    @endswitch
@endsection
