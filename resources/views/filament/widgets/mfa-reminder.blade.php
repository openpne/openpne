{{-- Admin-account 2FA nudge. Full-width alert, deliberately unlike the member-stat cards so an
     operator reads it as being about their own login. Only rendered while MFA is off (canView).
     Filament panel vars (--gray-*) do not auto-flip, so dark mode is handled with .dark overrides,
     as in components/image-lightbox.blade.php. --}}
<x-filament-widgets::widget>
    <div class="op-mfa-alert">
        <x-filament::icon icon="heroicon-o-shield-exclamation" class="op-mfa-alert__icon" />
        <div class="op-mfa-alert__body">
            <div class="op-mfa-alert__title">
                {{ __('Your administrator account is not protected by two-factor authentication') }}
            </div>
            <div class="op-mfa-alert__text">
                {{ __('Add a one-time code from an authenticator app to your own admin login.') }}
            </div>
        </div>
        <x-filament::button tag="a" :href="$this->getSecurityUrl()" icon="heroicon-m-lock-closed" color="warning">
            {{ __('Set up two-factor authentication') }}
        </x-filament::button>
    </div>

    @once
        <style>
            .op-mfa-alert {
                display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
                padding: 1rem 1.25rem; border-radius: 0.75rem;
                border: 1px solid var(--warning-300);
                /* Translucent amber reads as a warning tint over either the light or the dark page. */
                background: rgba(245, 158, 11, 0.12);
            }
            .op-mfa-alert__icon { width: 1.75rem; height: 1.75rem; flex-shrink: 0; color: var(--warning-600); }
            .op-mfa-alert__body { flex: 1 1 16rem; min-width: 0; }
            .op-mfa-alert__title { font-weight: 600; color: var(--gray-950); }
            .op-mfa-alert__text { font-size: 0.875rem; color: var(--gray-500); }

            .dark .op-mfa-alert { border-color: var(--warning-500); }
            .dark .op-mfa-alert__icon { color: var(--warning-400); }
            .dark .op-mfa-alert__title { color: var(--gray-100); }
            .dark .op-mfa-alert__text { color: var(--gray-400); }
        </style>
    @endonce
</x-filament-widgets::widget>
