import { type MouseEvent, useContext, useLayoutEffect, useRef } from 'react';
import { OwnLinksOpen, visitInApp } from '@/components/body-link';
import { UserText } from '@/components/user-text';
import { useT } from '@/lib/i18n';
import { inAppHref, isPlainClick } from '@/lib/link-target';

/**
 * `bodyHtml` is exclusively the output of the server-side sanitizer pipeline, never constructed
 * client-side, and this is the one dangerouslySetInnerHTML site. A null `bodyHtml` means a plain
 * body, which takes the same path as <UserText>.
 */
export function RichBody({ body, bodyHtml }: { body: string; bodyHtml: string | null }) {
    const t = useT();
    const mode = useContext(OwnLinksOpen);
    const root = useRef<HTMLDivElement>(null);

    // The server left a link to this site as a bare anchor; where own links open a new tab, it is
    // given the tab and the notice <BodyLink> would give it, before it can be clicked.
    useLayoutEffect(() => {
        if (mode !== 'new-tab' || root.current === null) {
            return;
        }
        for (const anchor of root.current.querySelectorAll('a:not([target])')) {
            if (!(anchor instanceof HTMLAnchorElement) || inAppHref(anchor.href, window.location.host) === null) {
                continue;
            }
            anchor.target = '_blank';
            anchor.rel = 'noopener';
            const notice = document.createElement('span');
            notice.className = 'sr-only';
            notice.textContent = ` ${t('Opens in a new tab')}`;
            anchor.append(notice);
        }
    }, [bodyHtml, mode, t]);

    if (bodyHtml === null) {
        return (
            <div className="whitespace-pre-wrap break-words">
                <UserText text={body} />
            </div>
        );
    }

    // A bare anchor to this site is followed as <BodyLink> follows one.
    const onClick = (event: MouseEvent<HTMLDivElement>) => {
        const anchor = event.target instanceof Element ? event.target.closest('a') : null;

        if (!anchor || !event.currentTarget.contains(anchor) || anchor.target !== '' || !isPlainClick(event)) {
            return;
        }

        const path = inAppHref(anchor.href, window.location.host);

        if (path === null) {
            return;
        }

        event.preventDefault();
        visitInApp(path);
    };

    return <div ref={root} className="rich-body break-words" onClick={onClick} dangerouslySetInnerHTML={{ __html: bodyHtml }} />;
}
