import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Avatar } from '@/components/avatar';
import { CommunityImage } from '@/components/community-image';
import { headingVariants } from '@/components/ui/heading';
import { useT } from '@/lib/i18n';
import { breadcrumbCrumb, type Chrome } from '@/lib/member-chrome';
import { useScrolled } from '@/lib/use-scrolled';
import { cn } from '@/lib/utils';

/** One shape for both faces of the crumb, so the bar reads the same wherever the reader lands. */
const PILL = 'inline-flex min-w-0 items-center gap-1.5 rounded-full py-1';

/**
 * Only where a screen is inside something: home, a hub and anything the sidebar's own active row
 * already names render nothing (docs/internals/looks.md, "The registry"). It is a surface rather
 * than a pill floating on nothing — left transparent it took the clicks of everything that scrolled
 * under it.
 */
export function PlaceBar({ chrome }: { chrome: Chrome }) {
    const t = useT();
    const scrolled = useScrolled();
    const crumb = breadcrumbCrumb(chrome);

    if (!crumb) {
        return null;
    }

    const { scope } = chrome;
    const label = typeof crumb.label === 'string' ? crumb.label : t(crumb.label.key, crumb.label.replacements);
    // Absent where the place is a section rather than an entity (a tag lens crumbs to its feed), and
    // on a form, which the registry pins to no scope.
    let face: ReactNode = null;
    if (scope?.kind === 'group') {
        face = <CommunityImage name={scope.name} src={scope.imageUrl} className="size-6" textClassName="text-[10px]" decorative />;
    } else if (scope?.kind === 'member') {
        face = <Avatar id={scope.id} name={scope.name} src={scope.imageUrl} color={scope.avatarColor} isAi={scope.isAi} size="xs" decorative />;
    }

    const body = (
        <>
            {face}
            <span className="truncate">{label}</span>
        </>
    );
    // The face is drawn to the pill's edge; a bare name keeps the pill symmetrical.
    const padding = face ? 'pr-3 pl-1' : 'px-3';

    return (
        // The width cancels the frame's padding rather than taking the content column's: at the
        // column's width this bar is not a surface at all, its ground being the ground either side of
        // the card too.
        <div
            data-testid="place-bar"
            className={cn(
                'sticky top-[var(--modern-top-offset)] z-20 -mx-4 hidden min-w-0 border-b bg-background px-4 pt-2 pb-1 lg:flex',
                scrolled ? 'border-border' : 'border-transparent',
            )}
        >
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
        </div>
    );
}
