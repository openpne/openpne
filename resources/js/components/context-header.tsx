import { Link } from '@inertiajs/react';
import { useT } from '@/lib/i18n';

export interface ContextHeaderItem {
    href: string;
    label: string;
}

/**
 * Scope context above a page heading: where the screen lives (a community, and for board content the
 * board), each crumb a link back up. Kept separate from PageHeading so the heading stays h1 + action
 * only; the frame renders this from the chrome registry's `context`. The crumbs are all ancestors —
 * the current page is not among them — so no `aria-current` (WAI breadcrumb pattern).
 */
export function ContextHeader({ items }: { items: ContextHeaderItem[] }) {
    const t = useT();

    return (
        <nav aria-label={t('Breadcrumb')} className="flex min-w-0 flex-wrap items-center gap-1 text-sm">
            {items.map((item, i) => (
                <span key={item.href} className="flex min-w-0 items-center gap-1">
                    {i > 0 && <span aria-hidden>›</span>}
                    <Link
                        href={item.href}
                        className="truncate text-muted-foreground hover:text-foreground hover:underline"
                    >
                        {item.label}
                    </Link>
                </span>
            ))}
        </nav>
    );
}
