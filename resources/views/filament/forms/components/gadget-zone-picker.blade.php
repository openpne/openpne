<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    @once
        <style>
            .op-zp-hint { font-size:0.8125rem; color:var(--gray-500); }
            .op-zp-grid { display:grid; gap:0.5rem; }
            .op-zp-zone {
                position:relative; display:block; cursor:pointer; min-height:3.75rem;
                border:1px dashed var(--gray-300); border-radius:0.5rem; padding:0.5rem;
                transition:border-color .15s, box-shadow .15s, opacity .15s;
            }
            .op-zp-zone:hover { border-color:var(--gray-400); }
            .op-zp-zone:focus-within { outline:2px solid var(--primary-500); outline-offset:2px; }
            .op-zp-zone.is-selected { border-style:solid; border-color:var(--primary-500); box-shadow:0 0 0 1px var(--primary-500); }
            .op-zp-zone.is-dim { opacity:0.5; }
            .op-zp-input { position:absolute; width:1px; height:1px; opacity:0; }
            .op-zp-label { font-size:0.625rem; text-transform:uppercase; letter-spacing:0.03em; color:var(--gray-500); margin-bottom:0.25rem; }
            .op-zp-chip {
                display:inline-block; font-size:0.6875rem; color:var(--gray-700);
                border:1px solid var(--gray-300); border-radius:0.375rem; padding:0.0625rem 0.375rem; margin:0.0625rem;
            }
            .op-zp-empty { font-size:0.6875rem; color:var(--gray-400); }
            .op-zp-note { font-size:0.625rem; color:var(--gray-400); margin-top:0.25rem; }
            .op-zp-placing {
                position:absolute; top:0.375rem; right:0.5rem; font-size:0.625rem; font-weight:700;
                color:var(--primary-600); opacity:0; transition:opacity .15s;
            }
            .op-zp-zone.is-selected .op-zp-placing { opacity:1; }

            .dark .op-zp-hint { color:var(--gray-400); }
            .dark .op-zp-zone { border-color:var(--gray-700); }
            .dark .op-zp-zone:hover { border-color:var(--gray-600); }
            .dark .op-zp-label { color:var(--gray-400); }
            .dark .op-zp-chip { color:var(--gray-200); border-color:var(--gray-600); }
            .dark .op-zp-empty,
            .dark .op-zp-note { color:var(--gray-500); }
            .dark .op-zp-placing { color:var(--primary-400); }
        </style>
    @endonce

    @php
        $context = (string) $get('context');
        $zones = $context !== '' ? \App\Filament\Resources\Gadgets\GadgetResource::zoneOptions($context) : [];
        $placements = $context !== '' ? \App\Filament\Resources\Gadgets\GadgetResource::placements($context) : [];
        $activeZones = $context !== '' ? app(\App\Services\GadgetService::class)->activeZones($context) : [];
        // Only widest layout shapes occur here: layoutA (has `top`) for home/profile/login, else a single
        // `contents` box for sidebanner. grid-area names are the zone keys.
        $grid = array_key_exists('top', $zones)
            ? "grid-template-columns:1fr 2fr;grid-template-areas:'top top' 'sideMenu contents' 'bottom bottom';"
            : 'grid-template-columns:1fr;grid-template-areas:'.collect(array_keys($zones))->map(fn ($z) => "'{$z}'")->implode(' ').';';
    @endphp

    @if ($context === '')
        <p class="op-zp-hint">{{ __('Select a placement first.') }}</p>
    @else
        <div
            x-data="{ state: $wire.$entangle('{{ $getStatePath() }}') }"
            role="radiogroup"
            class="op-zp-grid"
            style="{{ $grid }}"
        >
            @foreach ($zones as $zoneKey => $zoneLabel)
                @php($active = in_array($zoneKey, $activeZones, true))
                <label
                    class="op-zp-zone @unless ($active) is-dim @endunless"
                    style="grid-area: {{ $zoneKey }};"
                    :class="{ 'is-selected': state === @js($zoneKey) }"
                >
                    <input type="radio" class="op-zp-input" name="{{ $getStatePath() }}" value="{{ $zoneKey }}" x-model="state" />
                    <span class="op-zp-placing">&check; {{ __('Placing here') }}</span>
                    <span class="op-zp-label">{{ $zoneLabel }}</span>
                    @forelse ($placements[$zoneKey] ?? [] as $chip)
                        <span class="op-zp-chip">{{ $chip }}</span>
                    @empty
                        <span class="op-zp-empty">{{ __('(empty)') }}</span>
                    @endforelse
                    @unless ($active)
                        <div class="op-zp-note">{{ __('Not shown in the current layout.') }}</div>
                    @endunless
                </label>
            @endforeach
        </div>
    @endif
</x-dynamic-component>
