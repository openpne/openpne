import { Link, usePage } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

/**
 * Mobile (< lg) diary-compose shortcut, scoped to the dashboard. Diary compose is fixed (OpenPNE's
 * differentiation), but a floating button reads as "post to THIS screen" and misleads on e.g. the
 * timeline — so the FAB shows only on the dashboard, the diary-forward home where a diary shortcut is
 * in context. Every other screen carries its own in-page primary action. The desktop sidebar keeps the
 * same action as a labelled pill (no such misread), so this is hidden at lg+.
 */
export function PostFab() {
    const t = useT();
    const { url, props } = usePage<PageProps>();

    if (!props.auth.user || !props.enabledFeatures.diary) {
        return null;
    }
    // Exact pathname match (strip query/hash), not a prefix.
    if (url.replace(/[?#].*$/, '') !== '/dashboard') {
        return null;
    }

    // The nav landmark keeps this action inside a region (axe) and carries the fixed positioning, so
    // no blurred/transformed ancestor becomes its containing block.
    return (
        <nav
            aria-label={t('Post %diary%')}
            className="fixed right-[calc(1.25rem+env(safe-area-inset-right))] bottom-[calc(1.25rem+var(--modern-bottom-offset))] z-30 lg:hidden"
        >
            <Link
                href="/diary/new"
                aria-label={t('Post %diary%')}
                className="inline-flex size-14 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-lg transition hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background active:scale-[0.97]"
            >
                <Pencil className="size-6" strokeWidth={2.25} />
            </Link>
        </nav>
    );
}
