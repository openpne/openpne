import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { CrumbText } from '@/components/crumb-text';
import { PlaceFace, PlaceSwitch } from '@/components/place-switch';
import { headingVariants } from '@/components/ui/heading';
import { breadcrumbCrumb, type Chrome, headerOf } from '@/lib/member-chrome';
import { useScrolled } from '@/lib/use-scrolled';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

/** One shape and one height whether the crumb links or is plain text: what sticks below the bar offsets by the single `--modern-place-bar` the shell publishes. */
const PILL = 'inline-flex h-8 min-w-0 items-center gap-1.5 rounded-full';

/**
 * Only where a screen is inside something: home, a hub and anything the sidebar's own active row
 * already names render nothing (docs/internals/modern-frame.md, "Desk width"). It is a surface rather
 * than a pill floating on nothing — left transparent it took the clicks of everything that scrolled
 * under it.
 */
export function PlaceBar({ chrome }: { chrome: Chrome }) {
    const { component, props } = usePage<PageProps>();
    const guest = props.auth.user === null;
    const header = headerOf(chrome, { component: String(component), home: props.modernHome, guest });

    if (header.type === 'place') {
        return (
            <Bar>
                <PlaceSwitch scope={header.scope} variant="desk" />
            </Bar>
        );
    }

    const crumb = header.type === 'trail' ? breadcrumbCrumb(chrome, guest) : null;
    if (!crumb) {
        return null;
    }

    // The face stands for the member's own page, where a guest's crumb never leads.
    const scope = guest ? undefined : chrome.scope;
    // Absent where the place is a section rather than an entity (a tag lens crumbs to its feed), and
    // on a form, which the registry pins to no scope.
    const face = scope ? <PlaceFace scope={scope} /> : null;
    const body = (
        <>
            {face}
            <CrumbText label={crumb.label} />
        </>
    );
    // The face is drawn to the pill's edge; a bare name keeps the pill symmetrical.
    const padding = face ? 'pr-3 pl-1' : 'px-3';

    return (
        <Bar>
            {crumb.link ? (
                // The phone pill's fill, unchanged: the same element means the same thing at both
                // widths, and it is the affordance the bare trail failed to be.
                <Link href={crumb.href} className={cn(PILL, padding, headingVariants({ variant: 'bar' }), 'bg-accent')}>
                    {body}
                </Link>
            ) : (
                // A form's crumb goes nowhere, so it is painted as text — in the ground color, which
                // shows as nothing until the form scrolls under it.
                <span className={cn(PILL, padding, 'bg-background text-sm text-muted-foreground')}>{body}</span>
            )}
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
