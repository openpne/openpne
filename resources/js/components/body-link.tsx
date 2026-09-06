import { router } from '@inertiajs/react';
import { createContext, type MouseEvent, type ReactNode, useContext } from 'react';
import { useT } from '@/lib/i18n';
import { inAppHref, isPlainClick } from '@/lib/link-target';

export const EXTERNAL_REL = 'noopener noreferrer nofollow';

/** For a link to this site that opens a new tab all the same. */
export const OWN_TAB_REL = 'noopener';

/**
 * How a link to this site opens: through the router, or in a new tab where a click must not take
 * the page with it (the compose preview and its draft).
 */
export const OwnLinksOpen = createContext<'in-app' | 'new-tab'>('in-app');

/**
 * A page of ours that answers without an Inertia page (a file, the admin) is loaded outright, rather
 * than shown in the router's error overlay; an Inertia error page is left to the router.
 */
export function visitInApp(path: string): void {
    router.visit(path, {
        onHttpException: (response) => {
            if (response.headers['x-inertia']) {
                return;
            }
            window.location.assign(path);

            return false;
        },
    });
}

/** See docs/internals/body-text.md, "Where a link opens". */
export function BodyLink({ href, className, children }: { href: string; className?: string; children: ReactNode }) {
    const t = useT();
    const mode = useContext(OwnLinksOpen);
    const path = inAppHref(href, window.location.host);

    if (path === null || mode === 'new-tab') {
        return (
            <a href={path ?? href} target="_blank" rel={path === null ? EXTERNAL_REL : OWN_TAB_REL} className={className}>
                {children}
                <span className="sr-only"> {t('Opens in a new tab')}</span>
            </a>
        );
    }

    const onClick = (event: MouseEvent<HTMLAnchorElement>) => {
        if (!isPlainClick(event)) {
            return;
        }
        event.preventDefault();
        visitInApp(path);
    };

    return (
        <a href={path} className={className} onClick={onClick}>
            {children}
        </a>
    );
}
