{{-- part=rows goes inside the caller's <table>; part=recovery after it, since a disclosure cannot wrap a table row.
     part=inline is the whole proof for a one-line form in a list, whose errors the list shows once. --}}
@php
    $showErrors ??= true;
    $allowRecoveryCode ??= true;
    $requiresSecondFactor ??= auth('member')->user()?->hasEnabledTwoFactorAuthentication() ?? false;
    // A caller that names no purpose offers the password alone.
    $offersPasskey = isset($purpose) && $part !== 'recovery' && once(fn (): bool => auth('member')->user()?->passkeys()->exists() ?? false);
    $passkeyMessages = $offersPasskey ? json_encode([
        'The passkey prompt was cancelled.' => __('The passkey prompt was cancelled.'),
        'This browser does not support passkeys.' => __('This browser does not support passkeys.'),
        'Too many attempts. Please wait a moment and try again.' => __('Too many attempts. Please wait a moment and try again.'),
        'The passkey could not be used. Please try again.' => __('The passkey could not be used. Please try again.'),
    ]) : null;
@endphp
@if ($offersPasskey)
    @once
        @vite('resources/js/passkeys-classic.ts')
    @endonce
@endif
@if ($part === 'rows')
    <tr>
        <th><label for="{{ $idPrefix }}_password">{{ __('Current password') }}</label></th>
        <td>
            <input type="password" id="{{ $idPrefix }}_password" name="current_password" autocomplete="current-password">
            @isset($passwordHelp)<p>{{ $passwordHelp }}</p>@endisset
            @if ($showErrors)
                @error('current_password')<p class="error" role="alert">{{ $message }}</p>@enderror
            @endif
        </td>
    </tr>
    @if ($offersPasskey)
        {{-- Hidden until the script confirms WebAuthn support. --}}
        <tr data-passkey-reauth-row hidden>
            <th>{{ __('Passkey') }}</th>
            <td>
                <button type="button" class="input_submit"
                        data-passkey-reauth
                        data-options-url="{{ route('member.reauth.passkey.options', ['purpose' => $purpose]) }}"
                        data-submit-url="{{ route('member.reauth.passkey.store') }}"
                        @if ($codeBesidePasskey ?? false) data-keeps-code @endif
                        data-messages="{{ $passkeyMessages }}">{{ __('Confirm with a passkey instead') }}</button>
                <p role="status" data-passkey-proved hidden>{{ __('Confirmed with your passkey. Please finish within two minutes.') }}</p>
                <p class="error" role="alert" data-passkey-error hidden></p>
            </td>
        </tr>
    @endif
    @if ($requiresSecondFactor)
        <tr>
            <th><label for="{{ $idPrefix }}_code">{{ __('Authentication code') }}</label></th>
            <td>
                <input type="text" id="{{ $idPrefix }}_code" name="code" class="input_text" inputmode="numeric" autocomplete="one-time-code">
                <p>{{ $codeHelp ?? __('Enter the six-digit code shown in your authenticator app.') }}</p>
                @if ($showErrors)
                    @error('code')<p class="error" role="alert">{{ $message }}</p>@enderror
                @endif
            </td>
        </tr>
    @endif
@elseif ($part === 'inline')
    <label for="{{ $idPrefix }}_password">{{ __('Current password') }}</label>
    <input type="password" id="{{ $idPrefix }}_password" name="current_password" class="input_text" autocomplete="current-password">
    @if ($offersPasskey)
        <span data-passkey-reauth-row hidden>
            <button type="button" class="input_submit"
                    data-passkey-reauth
                    data-options-url="{{ route('member.reauth.passkey.options', ['purpose' => $purpose]) }}"
                    data-submit-url="{{ route('member.reauth.passkey.store') }}"
                    data-messages="{{ $passkeyMessages }}">{{ __('Confirm with a passkey instead') }}</button>
            <span role="status" data-passkey-proved hidden>{{ __('Confirmed with your passkey. Please finish within two minutes.') }}</span>
            <span class="error" role="alert" data-passkey-error hidden></span>
        </span>
    @endif
    @if ($requiresSecondFactor)
        <label for="{{ $idPrefix }}_code">{{ __('Authentication code') }}</label>
        <input type="text" id="{{ $idPrefix }}_code" name="code" class="input_text" inputmode="numeric" autocomplete="one-time-code">
        <details>
            <summary>{{ __('Use a recovery code instead') }}</summary>
            <label for="{{ $idPrefix }}_recovery_code">{{ __('Recovery code') }}</label>
            <input type="text" id="{{ $idPrefix }}_recovery_code" name="recovery_code" class="input_text" autocomplete="off">
        </details>
    @endif
@elseif ($requiresSecondFactor && $allowRecoveryCode)
    <details @if ($showErrors && $errors->has('recovery_code')) open @endif>
        <summary>{{ __('Use a recovery code instead') }}</summary>
        <table>
            <tr>
                <th><label for="{{ $idPrefix }}_recovery_code">{{ __('Recovery code') }}</label></th>
                <td>
                    <input type="text" id="{{ $idPrefix }}_recovery_code" name="recovery_code" class="input_text" autocomplete="off">
                    <p>{{ __('Each recovery code can be used once, if you no longer have your authenticator.') }}</p>
                    @if ($showErrors)
                        @error('recovery_code')<p class="error" role="alert">{{ $message }}</p>@enderror
                    @endif
                </td>
            </tr>
        </table>
    </details>
@endif
