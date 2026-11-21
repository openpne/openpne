import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { PlaceSwitch } from '@/components/place-switch';
import { headingVariants } from '@/components/ui/heading';
import { useT } from '@/lib/i18n';
import { type Chrome, headerOf } from '@/lib/member-chrome';
import { useScrolled } from '@/lib/use-scrolled';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

/** One shape and one height whether the bar holds a place or a title: what sticks below the bar offsets by the single `--modern-place-bar` the shell publishes. */
const PILL = 'inline-flex h-8 min-w-0 items-center gap-1.5 rounded-full';

/**
 * Only where a screen is inside something: home, a hub and anything the sidebar's own active row
 * already names render nothing (docs/internals/modern-frame.md, "Desk width"). It is a surface rather
 * than a pill floating on nothing — left transparent it took the clicks of everything that scrolled
 * under it.
 */
export function PlaceBar({ chrome }: { chrome: Chrome }) {
    const t = useT();
    const { component, props } = usePage<PageProps>();
    const guest = props.auth.user === null;
    const header = headerOf(chrome, { component: String(component), home: props.modernHome, guest });

    if (header.type === 'place') {
        return (
            <Bar>
                {header.pressable ? (
                    <PlaceSwitch scope={header.scope} variant="desk" />
                ) : (
                    // No face: it stands for the member's own page, which a guest may not be let into.
                    <span className={cn(PILL, 'px-3', headingVariants({ variant: 'bar' }))}>
                        <span className="min-w-0 truncate">{header.scope.name}</span>
                    </span>
                )}
            </Bar>
        );
    }

    if (header.type !== 'title' || !header.desk) {
        return null;
    }

    return (
        <Bar>
            {/* Nothing here is pressable, so it is painted as text — in the ground color, which shows as nothing until the page scrolls under it. */}
            <span className={cn(PILL, 'bg-background px-3 text-sm text-muted-foreground')}>
                <span className="min-w-0 truncate">{typeof header.label === 'string' ? header.label : t(header.label.key, header.label.replacements)}</span>
            </span>
        </Bar>
    );
}

function Bar({ children }: { children: ReactNode }) {
    const scrolled = useScrolled();

    return (
        // The width cancels the frame's padding rather than taking the content column's: at the
        // column's width this bar is not a surface at all, its ground being the ground either side of
        // the card too.
        <div
            data-testid="place-bar"
            className={cn(
                'sticky top-(--modern-top-offset) z-20 -mx-4 hidden min-w-0 border-b bg-background px-4 pt-2 pb-1 lg:flex',
                scrolled ? 'border-border' : 'border-transparent',
            )}
        >
            {children}
        </div>
    );
}
