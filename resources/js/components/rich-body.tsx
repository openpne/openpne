import { type MouseEvent, useContext, useLayoutEffect, useMemo, useRef } from 'react';
import { OWN_TAB_REL, OwnLinksOpen, visitInApp } from '@/components/body-link';
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
    // One object per body: React rewrites innerHTML whenever the object is new, not when the text is.
    const innerHtml = useMemo(() => (bodyHtml === null ? null : { __html: bodyHtml }), [bodyHtml]);

    // Where own links open a new tab, the bare anchor the server left is given the tab and the
    // notice <BodyLink> would give it before it can be clicked, and loses them when that stops.
    useLayoutEffect(() => {
        if (mode !== 'new-tab' || root.current === null) {
            return;
        }
        const marked: HTMLAnchorElement[] = [];
        for (const anchor of root.current.querySelectorAll('a:not([target])')) {
            if (!(anchor instanceof HTMLAnchorElement) || inAppHref(anchor.href, window.location.host) === null) {
                continue;
            }
            anchor.target = '_blank';
            anchor.rel = OWN_TAB_REL;
            const notice = document.createElement('span');
            notice.className = 'sr-only';
            notice.textContent = ` ${t('Opens in a new tab')}`;
            anchor.append(notice);
            marked.push(anchor);
        }

        return () => {
            for (const anchor of marked) {
                anchor.removeAttribute('target');
                anchor.removeAttribute('rel');
                anchor.querySelector(':scope > .sr-only')?.remove();
            }
        };
    }, [bodyHtml, mode, t]);

    if (innerHtml === null) {
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

    return <div ref={root} className="rich-body break-words" onClick={onClick} dangerouslySetInnerHTML={innerHtml} />;
}
