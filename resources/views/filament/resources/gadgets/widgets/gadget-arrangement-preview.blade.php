<div>
    <style>
        .op-gp-wrap { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:1rem; }
        .op-gp-card { border:1px solid var(--gray-200); border-radius:0.75rem; padding:0.75rem; }
        .op-gp-head { display:flex; align-items:center; justify-content:space-between; gap:0.5rem; margin-bottom:0.5rem; }
        .op-gp-title { font-size:0.875rem; font-weight:600; color:var(--gray-700); }
        .op-gp-badge { flex:0 0 auto; font-size:0.6875rem; font-weight:700; color:var(--primary-600); border:1px solid var(--primary-500); border-radius:9999px; padding:0.0625rem 0.5rem; }
        .op-gp-grid { display:grid; gap:0.375rem; }
        .op-gp-zone { border:1px dashed var(--gray-300); border-radius:0.5rem; padding:0.375rem; min-height:3rem; }
        .op-gp-zone-label { font-size:0.625rem; text-transform:uppercase; letter-spacing:0.03em; color:var(--gray-500); margin-bottom:0.25rem; }
        .op-gp-chip { display:inline-block; font-size:0.6875rem; color:var(--gray-700); border:1px solid var(--gray-300); border-radius:0.375rem; padding:0.0625rem 0.375rem; margin:0.0625rem; }
        .op-gp-empty { font-size:0.6875rem; color:var(--gray-400); }
        .op-gp-note { margin-top:0.5rem; font-size:0.6875rem; color:var(--gray-500); }
        .dark .op-gp-card { border-color:var(--gray-700); }
        .dark .op-gp-title { color:var(--gray-100); }
        .dark .op-gp-badge { color:var(--primary-400); border-color:var(--primary-500); }
        .dark .op-gp-zone { border-color:var(--gray-700); }
        .dark .op-gp-zone-label { color:var(--gray-400); }
        .dark .op-gp-chip { color:var(--gray-200); border-color:var(--gray-600); }
        .dark .op-gp-empty { color:var(--gray-500); }
        .dark .op-gp-note { color:var(--gray-400); }
    </style>

    <div class="op-gp-wrap">
        @foreach ($this->previews() as $preview)
            @php
                // Layout geometry is intentionally duplicated here (content-bearing HTML mock) vs the
                // picker's thumbnail SVG; both derive the zone set from GadgetLayout. Keyed by the
                // normalized letter (layoutLetter()), so an unknown stored layout falls back like the renderer.
                $grid = match ($preview['letter']) {
                    'A' => "grid-template-columns:1fr 2fr;grid-template-areas:'top top' 'sideMenu contents' 'bottom bottom';",
                    'B' => "grid-template-columns:1fr 2fr;grid-template-areas:'sideMenu contents' 'bottom bottom';",
                    'C' => "grid-template-columns:1fr;grid-template-areas:'contents' 'bottom';",
                    default => "grid-template-columns:1fr;grid-template-areas:'contents';",
                };
            @endphp

            <div class="op-gp-card">
                <div class="op-gp-head">
                    <span class="op-gp-title">{{ $preview['label'] }}</span>
                    <span class="op-gp-badge">Layout {{ $preview['letter'] }}</span>
                </div>

                <div class="op-gp-grid" style="{{ $grid }}">
                    @foreach ($preview['zones'] as $zone)
                        <div class="op-gp-zone" style="grid-area: {{ $zone['key'] }};">
                            <div class="op-gp-zone-label">{{ $zone['label'] }}</div>
                            @forelse ($zone['chips'] as $chip)
                                <span class="op-gp-chip">{{ $chip }}</span>
                            @empty
                                <span class="op-gp-empty">&mdash;</span>
                            @endforelse
                        </div>
                    @endforeach
                </div>

                @if ($preview['notShown'] > 0)
                    <div class="op-gp-note">
                        {{ __(':count gadget(s) configured here are not shown in this layout (see the table below).', ['count' => $preview['notShown']]) }}
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</div>
