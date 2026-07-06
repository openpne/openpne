import { Link } from '@inertiajs/react';

export interface ContextHeaderItem {
    href: string;
    label: string;
}

/**
 * Scope context above a page heading: where the screen lives (a community, and for board content the
 * board), each crumb a link back up. Kept separate from PageHeading so the heading stays h1 + action
 * only; the frame renders this from the chrome registry's `context`.
 */
export function ContextHeader({ items }: { items: ContextHeaderItem[] }) {
    return (
        <div className="flex min-w-0 flex-wrap items-center gap-1 text-sm">
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
        </div>
    );
}
