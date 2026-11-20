import type { ReactNode } from 'react';
import { Heading } from '@/components/ui/heading';

/** A section's name set between two rules, as a printed page heads a column. */
export function SectionLabel({ children }: { children: ReactNode }) {
    return (
        <div className="flex items-center gap-3">
            <div aria-hidden className="h-px flex-1 bg-foreground" />
            <Heading as="h2" variant="kicker" className="text-center">
                {children}
            </Heading>
            <div aria-hidden className="h-px flex-1 bg-foreground" />
        </div>
    );
}
