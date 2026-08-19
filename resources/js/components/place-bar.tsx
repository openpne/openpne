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
 * The desktop answer to "where am I": the place the screen is inside, sticky at the head of the
 * content column. It replaces the crumb trail rather than joining it — this look answers the question
 * in one element at every width, and two answers stacked would be the question asked twice.
 *
 * Only where a screen is inside something. Home, a hub and anything the sidebar's own active row
 * already names render nothing, which is what keeps this furniture rather than a second header.
 *
 * The crumb is the phone header's (`breadcrumbCrumb`), down to the pill and to a form's crumb being
 * static text. What this bar adds is the place's face: the one-image rule belongs to the phone
 * header, where the brand mark is that image — here the sidebar holds the mark, so the pill is free
 * to carry the face the reader is standing in.
 *
 * It is a surface, not a pill floating on nothing. Left transparent it took the clicks of everything
 * that scrolled under it — a strip of the content column wide and 32px tall where a link could be
 * seen and not pressed — and let that content show through around the pill besides. The treatment is
 * the phone header's (components/top-nav): the page's own ground at nine tenths with a blur behind
 * it, and a seam drawn only once something has scrolled under. Every desktop client this bar answers
 * to draws its place as a surface too.
 *
 * The remove the pill keeps from the top is padding rather than offset, which is the other half of
 * that header's shape and the half a surface cannot do without. Held off the top instead, the bar
 * leaves a window above itself for rows to travel through — invisible while it was transparent,
 * since there was no surface for them to be beside, and a seam the moment there is one.
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
        // Under the color line at the same remove every other sticky band in the app keeps, and below
        // lg nothing: the header carries this there. Width is the content column's, from the frame.
        <div
            data-testid="place-bar"
            className={cn(
                'sticky top-[var(--modern-top-offset)] z-20 hidden min-w-0 border-b bg-background/90 pt-2 pb-1 backdrop-blur lg:flex',
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
