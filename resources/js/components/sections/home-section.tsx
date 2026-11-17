import { Link } from '@inertiajs/react';
import { ChevronRight, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { Heading } from '@/components/ui/heading';
import { cn } from '@/lib/utils';

/**
 * Softer-cornered and borderless on purpose, unlike the app-wide Card: the pages that use it
 * separate their blocks with background and radius alone.
 */
export const HOME_CARD = 'overflow-hidden rounded-tile bg-card text-card-foreground';

export function HomeSection({
    title,
    icon: Icon,
    right,
    viewAll,
    children,
    bodyClassName,
}: {
    /** ReactNode, not a string: a section whose `right` carries a count puts the words for it in
     *  here as `sr-only`, since the pill beside the heading names nothing (components/count-pill.tsx). */
    title: ReactNode;
    icon?: LucideIcon;
    /** Attention the heading itself carries — an unread pill, not a control. */
    right?: ReactNode;
    viewAll?: { label: string; href: string };
    children: ReactNode;
    bodyClassName?: string;
}) {
    return (
        <section className={HOME_CARD}>
            <div className="flex items-center gap-2 px-4 pt-4 sm:px-5">
                {Icon && <Icon className="size-4.5 shrink-0 text-primary" aria-hidden />}
                <Heading as="h2" variant="section" className="min-w-0 flex-1 truncate">
                    {title}
                </Heading>
                {right}
                {viewAll && (
                    <Link
                        href={viewAll.href}
                        className="flex shrink-0 items-center gap-0.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                    >
                        {viewAll.label}
                        <ChevronRight className="size-4" aria-hidden />
                    </Link>
                )}
            </div>
            <div className={cn('px-4 pt-3 pb-4 sm:px-5', bodyClassName)}>{children}</div>
        </section>
    );
}

export function SubSection({ title, right, children }: { title: string; right?: ReactNode; children: ReactNode }) {
    return (
        <section>
            <div className="mb-2 flex items-center gap-2">
                <Heading as="h3" variant="minor" className="min-w-0 flex-1 truncate">
                    {title}
                </Heading>
                {right}
            </div>
            {children}
        </section>
    );
}
