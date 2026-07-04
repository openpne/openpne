{{-- Admin-account 2FA nudge. Full-width alert, deliberately unlike the member-stat cards so an
     operator reads it as being about their own login. Only rendered while MFA is off (canView). --}}
<x-filament-widgets::widget>
    <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;padding:1rem 1.25rem;border-radius:0.75rem;border:1px solid var(--warning-300, #fcd34d);background:var(--warning-50, #fffbeb);">
        <x-filament::icon
            icon="heroicon-o-shield-exclamation"
            style="width:1.75rem;height:1.75rem;flex-shrink:0;color:var(--warning-600, #d97706);"
        />
        <div style="flex:1 1 16rem;min-width:0;">
            <div style="font-weight:600;color:var(--gray-950, #09090b);">
                {{ __('Your administrator account is not protected by two-factor authentication') }}
            </div>
            <div style="font-size:0.875rem;color:var(--gray-500, #6b7280);">
                {{ __('Add a one-time code from an authenticator app to your own admin login.') }}
            </div>
        </div>
        <x-filament::button tag="a" :href="$this->getSecurityUrl()" icon="heroicon-m-lock-closed" color="warning">
            {{ __('Set up two-factor authentication') }}
        </x-filament::button>
    </div>
</x-filament-widgets::widget>
