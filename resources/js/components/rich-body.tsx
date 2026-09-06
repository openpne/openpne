import { type MouseEvent, useContext } from 'react';
import { followOwnLink, OwnLinksOpen } from '@/components/body-link';
import { UserText } from '@/components/user-text';
import { inAppHref, isPlainClick } from '@/lib/link-target';

/**
 * `bodyHtml` is exclusively the output of the server-side sanitizer pipeline, never constructed
 * client-side, and this is the one dangerouslySetInnerHTML site. A null `bodyHtml` means a plain
 * body, which takes the same path as <UserText>.
 */
export function RichBody({ body, bodyHtml }: { body: string; bodyHtml: string | null }) {
    const mode = useContext(OwnLinksOpen);

    if (bodyHtml === null) {
        return (
            <div className="whitespace-pre-wrap break-words">
                <UserText text={body} />
            </div>
        );
    }

    // The server left a link to this site as a bare anchor; it is followed as <BodyLink> follows one.
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
        followOwnLink(path, mode);
    };

    return <div className="rich-body break-words" onClick={onClick} dangerouslySetInnerHTML={{ __html: bodyHtml }} />;
}
