@php
    $current = app()->getLocale();
    $target = $current === 'ja' ? 'en' : 'ja';
    $label = $target === 'ja' ? '日本語' : 'English';
@endphp

<button
    type="button"
    tabindex="-1"
    title="{{ $label }}"
    style="display:inline-flex;align-items:center;gap:0.25rem;padding:0.25rem 0.5rem;border-radius:0.5rem;font-size:0.75rem;font-weight:500;color:var(--gray-500);transition:color 0.15s;background:transparent;border:0;cursor:pointer;"
    onmouseover="this.style.color='var(--primary-600)'"
    onmouseout="this.style.color='var(--gray-500)'"
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
    <x-filament::icon
        icon="heroicon-m-globe-alt"
        style="width:1rem;height:1rem;flex-shrink:0;"
    />
    <span>{{ $label }}</span>
</button>
