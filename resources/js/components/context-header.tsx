import { Link } from '@inertiajs/react';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export interface ContextHeaderItem {
    href: string;
    label: string;
    /** App vocabulary ("Topics", a message box) rather than member text — bounded by construction. */
    fixedLabel?: boolean;
}

/**
 * Scope context above a page heading: where the screen lives (a community, and for board content the
 * board), each crumb a link back up. Kept separate from PageHeading so the heading stays h1 + action
 * only; the frame renders this from the chrome registry's `context`. The crumbs are all ancestors —
 * the current page is not among them — so no `aria-current` (WAI breadcrumb pattern).
 *
 * The `bar` variant is the same trail on one line for the mobile top bar. Member-text crumbs (a
 * community, a topic, a message subject — unbounded) shrink with an ellipsis so the line always fits;
 * fixed-vocabulary crumbs keep their width, so a long community name never eats a short "Topics".
 */
export function ContextHeader({
    items,
    variant = 'page',
    className,
}: {
    items: ContextHeaderItem[];
    variant?: 'page' | 'bar';
    className?: string;
}) {
    const t = useT();

    return (
        <nav
            aria-label={t('Breadcrumb')}
            className={cn(
                variant === 'bar'
                    ? 'flex min-w-0 flex-1 items-center gap-1 overflow-hidden text-sm whitespace-nowrap'
                    : 'flex min-w-0 flex-wrap items-center gap-1 text-sm',
                className,
            )}
        >
            {items.map((item, i) => (
                <span
                    key={item.href}
                    className={cn('flex min-w-0 items-center gap-1', variant === 'bar' && item.fixedLabel && 'shrink-0')}
                >
                    {i > 0 && (
                        <span aria-hidden className={variant === 'bar' ? 'shrink-0' : undefined}>
                            ›
                        </span>
                    )}
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
