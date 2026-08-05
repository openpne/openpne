@php
    $current = app()->getLocale();
    $target = $current === 'ja' ? 'en' : 'ja';
    $label = $target === 'ja' ? '日本語' : 'English';
@endphp

{{-- Styling lives in CSS, not inline hover handlers: those wrote a light-mode colour back onto the
     element on mouseout, defeating the .dark override. Filament panel vars (--gray-*) do not
     auto-flip, so dark mode is handled with .dark overrides, as in widgets/mfa-reminder.blade.php. --}}
<button
    type="button"
    class="op-locale-switch"
    title="{{ __('Switch to :language', ['language' => $label]) }}"
    onclick="
        fetch('{{ route('locale.switch.session') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
            },
            body: JSON.stringify({ locale: '{{ $target }}' }),
        }).then(() => window.location.reload())
    "
>
    <x-filament::icon icon="heroicon-m-globe-alt" class="op-locale-switch__icon" />
    <span>{{ $label }}</span>
</button>

@once
    <style>
        .op-locale-switch {
            display: inline-flex; align-items: center; gap: 0.25rem;
            padding: 0.25rem 0.5rem; border-radius: 0.5rem;
            font-size: 0.75rem; font-weight: 500;
            background: transparent; border: 0; cursor: pointer;
            color: var(--gray-500); transition: color 0.15s;
        }
        .op-locale-switch:hover { color: var(--primary-600); }
        .op-locale-switch:focus-visible { outline: 2px solid var(--primary-600); outline-offset: 2px; }
        .op-locale-switch__icon { width: 1rem; height: 1rem; flex-shrink: 0; }

        /* --gray-400, not --gray-500: the latter is 3.7:1 on the dark topbar, under WCAG AA. */
        .dark .op-locale-switch { color: var(--gray-400); }
        .dark .op-locale-switch:hover { color: var(--primary-400); }
        .dark .op-locale-switch:focus-visible { outline-color: var(--primary-400); }
    </style>
@endonce
