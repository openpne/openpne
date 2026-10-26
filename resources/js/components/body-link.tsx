import { router } from '@inertiajs/react';
import { createContext, type MouseEvent, type ReactNode, useContext } from 'react';
import { useT } from '@/lib/i18n';
import { inAppHref, isBytesHref, isPlainClick } from '@/lib/link-target';
import { safeUrl } from '@/lib/safe-url';

export const EXTERNAL_REL = 'noopener noreferrer nofollow';

/** For a link to this site that opens a new tab all the same. */
export const OWN_TAB_REL = 'noopener';

/**
 * How a link to this site opens: through the router, or in a new tab where a click must not take
 * the page with it (the compose preview and its draft).
 */
export const OwnLinksOpen = createContext<'in-app' | 'new-tab'>('in-app');

/**
 * A page of ours that answers without an Inertia page (the admin) is loaded outright, rather than
 * shown in the router's error overlay; an Inertia error page is left to the router. A path served as
 * bytes never comes here: the browser follows it, since the router would download the file to find out.
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
    const url = safeUrl(href);

    if (url === null) {
        return <span className={className}>{children}</span>;
    }

    const path = inAppHref(url, window.location.host);

    if (path === null || mode === 'new-tab') {
        return (
            <a href={path ?? url} target="_blank" rel={path === null ? EXTERNAL_REL : OWN_TAB_REL} className={className}>
                {children}
                <span className="sr-only" data-new-tab-notice={t('Opens in a new tab')} />
            </a>
        );
    }

    const onClick = (event: MouseEvent<HTMLAnchorElement>) => {
        if (!isPlainClick(event) || isBytesHref(path)) {
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
