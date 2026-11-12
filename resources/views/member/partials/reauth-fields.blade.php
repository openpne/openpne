{{-- part=rows goes inside the caller's <table>; part=recovery after it, since a disclosure cannot wrap a table row.
     part=inline is the whole proof for a one-line form in a list, whose errors the list shows once. --}}
@php
    $showErrors ??= true;
    $allowRecoveryCode ??= true;
    $requiresSecondFactor ??= auth('member')->user()?->hasEnabledTwoFactorAuthentication() ?? false;
@endphp
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
