import { router } from '@inertiajs/react';
import { createContext, type MouseEvent, type ReactNode, useContext } from 'react';
import { useT } from '@/lib/i18n';
import { inAppHref, isPlainClick } from '@/lib/link-target';

export const EXTERNAL_REL = 'noopener noreferrer nofollow';

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

/** Follows a click on a link to this site the way <BodyLink> does, under the same {@link OwnLinksOpen}. */
export function followOwnLink(path: string, mode: 'in-app' | 'new-tab'): void {
    if (mode === 'new-tab') {
        window.open(path, '_blank', 'noopener');

        return;
    }
    visitInApp(path);
}

/** See docs/internals/body-text.md, "Where a link opens". */
export function BodyLink({ href, className, children }: { href: string; className?: string; children: ReactNode }) {
    const t = useT();
    const mode = useContext(OwnLinksOpen);
    const path = inAppHref(href, window.location.host);

    if (path === null) {
        return (
            <a href={href} target="_blank" rel={EXTERNAL_REL} className={className}>
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
        followOwnLink(path, mode);
    };

    return (
        <a href={path} className={className} onClick={onClick}>
            {children}
        </a>
    );
}
