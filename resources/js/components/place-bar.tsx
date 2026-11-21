import type { ReactNode } from 'react';
import { HeaderName } from '@/components/header-name';
import { type Header, hasPlaceBar } from '@/lib/member-chrome';
import { useScrolled } from '@/lib/use-scrolled';
import { cn } from '@/lib/utils';

/** A surface rather than a pill floating on nothing, since left transparent it took the clicks of everything that scrolled under it. */
export function PlaceBar({ header, action }: { header: Header; action?: ReactNode }) {
    if (!hasPlaceBar(header)) {
        return null;
    }

    return (
        <Bar>
            <HeaderName header={header} variant="desk" />
            {action && <div className="ml-auto shrink-0">{action}</div>}
        </Bar>
    );
}

/** Every child is 32px tall, so the bar is one height wherever it stands: what sticks below it offsets by the single `--modern-place-bar` the shell publishes. */
function Bar({ children }: { children: ReactNode }) {
    const scrolled = useScrolled();

    return (
        // The width cancels the frame's padding rather than taking the content column's: at the
        // column's width this bar is not a surface at all, its ground being the ground either side of
        // the card too.
        <div
            data-testid="place-bar"
            className={cn(
                'sticky top-(--modern-top-offset) z-20 -mx-4 hidden min-w-0 gap-3 border-b bg-background px-4 pt-2 pb-1 lg:flex',
                scrolled ? 'border-border' : 'border-transparent',
            )}
        >
            {children}
        </div>
    );
}
