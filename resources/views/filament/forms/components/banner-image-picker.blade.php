<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    @php
        $images = \App\Filament\Resources\BannerImages\BannerImageResource::pickerOptions();
        $lightboxClick = \App\Filament\Resources\BannerImages\BannerImageResource::LIGHTBOX_CLICK;
    @endphp

    @if ($images === [])
        <p class="op-bp-empty">{{ __('No banner images yet. Add one first.') }}</p>
    @else
        <div
            x-data="{ state: $wire.$entangle('{{ $getStatePath() }}') }"
            class="op-bp-grid"
        >
            @foreach ($images as $image)
                {{-- The whole card toggles selection (label + checkbox). The lightbox is a separate
                     corner button (stop.prevent) so clicking to select never opens it by accident.
                     Lightbox data rides on data-* attributes; the click expression reads $el.dataset. --}}
                <label class="op-bp-card">
                    <div class="op-bp-top">
                        <input type="checkbox" class="op-bp-check" value="{{ $image['id'] }}" x-model="state">
                        <button
                            type="button"
                            class="op-bp-zoom"
                            title="{{ __('Enlarge') }}"
                            data-lb-src="{{ $image['thumb'] }}"
                            data-lb-title="{{ $image['title'] }}"
                            data-lb-dims="{{ $image['dims'] }}"
                            data-lb-url="{{ $image['linkUrl'] }}"
                            x-on:click.stop.prevent="{{ $lightboxClick }}"
                        >
                            <x-filament::icon icon="heroicon-m-magnifying-glass-plus" style="width:1.25rem;height:1.25rem;" />
                        </button>
                    </div>
                    <img class="op-bp-thumb" src="{{ $image['thumb'] }}" alt="{{ $image['title'] }}">
                    <span class="op-bp-name">{{ $image['title'] !== '' ? $image['title'] : '#'.$image['id'] }}</span>
                    <span class="op-bp-dims">{{ $image['dims'] !== '' ? $image['dims'] : '—' }}</span>
                </label>
            @endforeach
        </div>
    @endif

    @once
        <style>
            .op-bp-grid {
                display: grid; gap: 0.75rem;
                grid-template-columns: repeat(auto-fill, minmax(11rem, 1fr));
            }
            /* The whole card is the selection target. */
            .op-bp-card {
                display: flex; flex-direction: column; gap: 0.375rem; padding: 0.5rem; cursor: pointer;
                border: 1px solid var(--gray-200); border-radius: 0.5rem; background: var(--gray-50);
                transition: border-color .15s, box-shadow .15s, background .15s;
            }
            .op-bp-card:hover { border-color: var(--gray-400); }
            .op-bp-card:has(.op-bp-check:checked) {
                border-color: var(--primary-500); box-shadow: 0 0 0 1px var(--primary-500);
                background: var(--primary-50);
            }
            .op-bp-top { display: flex; align-items: center; justify-content: space-between; min-height: 2rem; }
            .op-bp-check { width: 1.1rem; height: 1.1rem; cursor: pointer; }
            /* Distinct, hoverable control with a real hit area — not part of the selection target. */
            .op-bp-zoom {
                display: inline-flex; align-items: center; justify-content: center; width: 2rem; height: 2rem;
                color: var(--gray-600); background: var(--gray-100); border: 1px solid var(--gray-200);
                border-radius: 0.375rem; cursor: zoom-in;
            }
            .op-bp-zoom:hover { color: var(--gray-900); background: var(--gray-200); border-color: var(--gray-300); }
            /* Uniform height, ratio kept (the card frames it); pointer-events off so a click selects. */
            .op-bp-thumb {
                display: block; width: 100%; height: 5rem; object-fit: contain; pointer-events: none;
            }
            .op-bp-name {
                font-size: 0.8125rem; color: var(--gray-700);
                overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
            }
            .op-bp-dims { font-size: 0.75rem; color: var(--gray-500); }
            .op-bp-empty { font-size: 0.8125rem; color: var(--gray-500); }

            .dark .op-bp-card { border-color: var(--gray-700); background: var(--gray-900); }
            .dark .op-bp-card:hover { border-color: var(--gray-600); }
            .dark .op-bp-card:has(.op-bp-check:checked) { background: var(--gray-800); }
            .dark .op-bp-zoom { color: var(--gray-300); background: var(--gray-800); border-color: var(--gray-700); }
            .dark .op-bp-zoom:hover { color: var(--gray-50); background: var(--gray-700); border-color: var(--gray-600); }
            .dark .op-bp-name { color: var(--gray-200); }
            .dark .op-bp-dims { color: var(--gray-400); }
        </style>
    @endonce
</x-dynamic-component>
