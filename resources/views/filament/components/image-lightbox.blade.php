{{-- Shared image lightbox. Any thumbnail can open it by dispatching a bubbling `open-image-lightbox`
     event with { src, title, dims, linkUrl }. Kept client-side (Alpine) so it works from table cells,
     form previews and the banner picker alike without per-surface Livewire actions. --}}
<div
    x-data="{ open: false, src: '', title: '', dims: '', linkUrl: '' }"
    x-on:open-image-lightbox.window="
        src = $event.detail.src || '';
        title = $event.detail.title || '';
        dims = $event.detail.dims || '';
        linkUrl = $event.detail.linkUrl || '';
        open = true;
    "
    x-on:keydown.escape.window="open = false"
>
    <template x-if="open">
        <div class="op-lb-backdrop" x-on:click.self="open = false" x-transition.opacity>
            <div class="op-lb-box">
                <button type="button" class="op-lb-close" x-on:click="open = false" aria-label="{{ __('Close') }}">&times;</button>
                <img class="op-lb-img" :src="src" :alt="title">
                <div class="op-lb-meta">
                    <strong x-show="title" x-text="title"></strong>
                    <span x-show="dims" x-text="dims"></span>
                    <a x-show="linkUrl" :href="linkUrl" target="_blank" rel="noopener" class="op-lb-link">
                        {{ __('Link') }}: <span x-text="linkUrl"></span>
                    </a>
                    <a :href="src" target="_blank" rel="noopener" class="op-lb-link">{{ __('Open original in new tab') }}</a>
                </div>
            </div>
        </div>
    </template>

    @once
        <style>
            .op-lb-backdrop {
                position: fixed; inset: 0; z-index: 50; display: flex; align-items: center; justify-content: center;
                padding: 1.5rem; background: rgba(0, 0, 0, 0.7);
            }
            .op-lb-box {
                position: relative; max-width: min(90vw, 60rem); max-height: 90vh; display: flex; flex-direction: column;
                gap: 0.625rem; padding: 1rem; border-radius: 0.75rem; background: var(--gray-50); overflow: auto;
            }
            .op-lb-img {
                display: block; margin: 0 auto; max-width: 100%; max-height: 70vh; border-radius: 0.375rem;
            }
            .op-lb-meta {
                display: flex; flex-wrap: wrap; align-items: center; gap: 0.25rem 1rem;
                font-size: 0.8125rem; color: var(--gray-600);
            }
            .op-lb-link { color: var(--primary-600); text-decoration: underline; word-break: break-all; }
            .op-lb-close {
                position: absolute; top: 0.375rem; right: 0.5rem; width: 1.75rem; height: 1.75rem; line-height: 1;
                font-size: 1.375rem; color: var(--gray-500); background: transparent; border: 0; cursor: pointer;
            }
            .op-lb-close:hover { color: var(--gray-800); }

            .dark .op-lb-box { background: var(--gray-900); }
            .dark .op-lb-meta { color: var(--gray-400); }
            .dark .op-lb-close { color: var(--gray-400); }
            .dark .op-lb-close:hover { color: var(--gray-100); }
        </style>
    @endonce
</div>
