import { router } from '@inertiajs/react';
import { type MouseEvent, type ReactNode } from 'react';
import { useT } from '@/lib/i18n';
import { inAppHref, isPlainClick } from '@/lib/link-target';

export const EXTERNAL_REL = 'noopener noreferrer nofollow';

/**
 * A page of ours that answers without an Inertia page (a file, the admin) is loaded outright, rather
 * than shown in the router's error overlay.
 */
export function visitInApp(path: string): void {
    router.visit(path, {
        onHttpException: () => {
            window.location.assign(path);

            return false;
        },
    });
}

/** See docs/internals/body-text.md, "Where a link opens". */
export function BodyLink({ href, className, children }: { href: string; className?: string; children: ReactNode }) {
    const t = useT();
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
        visitInApp(path);
    };

    return (
        <a href={path} className={className} onClick={onClick}>
            {children}
        </a>
    );
}
