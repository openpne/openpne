import type { ReactNode } from 'react';
import { HeaderName } from '@/components/header-name';
import { type Header, hasPlaceBar } from '@/lib/member-chrome';

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

/**
 * Its row is the sidebar's site row (12px under the color line, 44px tall) and its line that row's foot, so the name is level with the site's.
 */
function Bar({ children }: { children: ReactNode }) {
    return (
        // The width cancels the frame's padding rather than taking the content column's: at the
        // column's width this bar is not a surface at all, its ground being the ground either side of
        // the card too.
        <div
            data-testid="place-bar"
            className="sticky top-(--modern-top-offset) z-20 -mx-4 mb-8 hidden h-(--modern-place-bar) min-w-0 items-center gap-3 border-b border-border bg-background px-4 pt-3 lg:flex"
        >
            {children}
        </div>
    );
}
