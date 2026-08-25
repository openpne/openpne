import { Search } from 'lucide-react';
import { Spinner } from '@/components/spinner';
import { Tip } from '@/components/ui/tooltip';
import { useT } from '@/lib/i18n';

/**
 * The magnifier submit that sits inside a rounded search Input (diary feed, member/community search,
 * right rail). It stays in the DOM so Enter / mobile / IME submission stays reliable; while the
 * request is in flight it swaps the icon for a spinner and disables, so a slow GET shows progress.
 * Position (absolute, right-aligned) is baked in — render it inside the Input's `relative` wrapper.
 */
export function SearchSubmitButton({ loading = false }: { loading?: boolean }) {
    const t = useT();
    return (
        <Tip label={t('Search')}>
            <button
                type="submit"
                aria-busy={loading}
                disabled={loading}
                className="absolute top-1/2 right-1.5 flex size-9 -translate-y-1/2 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-60"
            >
                {loading ? <Spinner size={4} /> : <Search className="size-4" aria-hidden />}
            </button>
        </Tip>
    );
}
