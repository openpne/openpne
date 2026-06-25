<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    @once
        <style>
            .op-gl-group { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:0.75rem; }
            .op-gl-card {
                position:relative; display:flex; flex-direction:column; align-items:center; gap:0.5rem;
                padding:1rem 0.75rem 0.75rem; border:1px solid var(--gray-200); border-radius:0.75rem;
                color:var(--gray-400); cursor:pointer;
                transition:border-color .15s, box-shadow .15s, color .15s;
            }
            .op-gl-card:hover { border-color:var(--gray-300); box-shadow:0 1px 4px rgba(0,0,0,0.10); }
            .op-gl-card:focus-within { outline:2px solid var(--primary-500); outline-offset:2px; }
            .op-gl-card.is-selected {
                border-color:var(--primary-500); color:var(--primary-600); box-shadow:0 0 0 1px var(--primary-500);
            }
            .op-gl-input { position:absolute; width:1px; height:1px; opacity:0; }
            .op-gl-badge {
                position:absolute; top:0.5rem; right:0.625rem; font-size:0.6875rem; font-weight:700;
                color:var(--primary-600); opacity:0; transition:opacity .15s;
            }
            .op-gl-card.is-selected .op-gl-badge { opacity:1; }
            .op-gl-wire { display:block; width:100%; max-width:9rem; color:inherit; }
            .op-gl-meta { display:flex; align-items:center; gap:0.375rem; }
            .op-gl-radio {
                flex:0 0 auto; width:1rem; height:1rem; border-radius:9999px; border:2px solid var(--gray-300);
                display:inline-flex; align-items:center; justify-content:center; transition:border-color .15s;
            }
            .op-gl-radio::after {
                content:''; width:0.5rem; height:0.5rem; border-radius:9999px; background:var(--primary-600);
                transform:scale(0); transition:transform .15s;
            }
            .op-gl-card.is-selected .op-gl-radio { border-color:var(--primary-600); }
            .op-gl-card.is-selected .op-gl-radio::after { transform:scale(1); }
            .op-gl-name { font-size:0.875rem; font-weight:600; color:var(--gray-700); }
            .op-gl-zones { font-size:0.6875rem; line-height:1.3; text-align:center; color:var(--gray-500); }

            /* Filament's --gray-* is an absolute scale (not flipped for dark), so dark text needs lighter shades. */
            .dark .op-gl-card { border-color:var(--gray-700); color:var(--gray-300); }
            .dark .op-gl-card:hover { border-color:var(--gray-600); }
            .dark .op-gl-card.is-selected { border-color:var(--primary-500); color:var(--primary-400); }
            .dark .op-gl-name { color:var(--gray-100); }
            .dark .op-gl-zones { color:var(--gray-400); }
            .dark .op-gl-radio { border-color:var(--gray-600); }
            .dark .op-gl-card.is-selected .op-gl-radio { border-color:var(--primary-400); }
            .dark .op-gl-radio::after { background:var(--primary-400); }
            .dark .op-gl-badge { color:var(--primary-400); }
        </style>
    @endonce

    <div
        x-data="{ state: $wire.$entangle('{{ $getStatePath() }}') }"
        role="radiogroup"
        class="op-gl-group"
    >
        @foreach ($getLayoutOptions() as $option)
            <label class="op-gl-card" :class="{ 'is-selected': state === @js($option['value']) }">
                <input
                    type="radio"
                    class="op-gl-input"
                    name="{{ $getStatePath() }}"
                    value="{{ $option['value'] }}"
                    x-model="state"
                />
                <span class="op-gl-badge">&check; {{ __('Selected') }}</span>
                <span class="op-gl-wire">
                    <x-admin.layout-wireframe :layout="$option['value']" />
                </span>
                <span class="op-gl-meta">
                    <span class="op-gl-radio" aria-hidden="true"></span>
                    <span class="op-gl-name">{{ $option['name'] }}</span>
                </span>
                <span class="op-gl-zones">{{ $option['zones'] }}</span>
            </label>
        @endforeach
    </div>
</x-dynamic-component>
