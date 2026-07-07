import { Fragment } from 'react';
import { linkify } from '@/lib/linkify';

/**
 * Renders a plain-text body with bare URLs turned into links, matching the Classic <x-user-text> /
 * App\Support\BodyText output. The parent element supplies whitespace-pre-wrap so newlines render as
 * line breaks; React escapes the visible text and the href, so this is XSS-safe without
 * dangerouslySetInnerHTML.
 */
export function UserText({ text }: { text: string | null | undefined }) {
    return (
        <>
            {linkify(text).map((segment, i) =>
                segment.type === 'url' ? (
                    <a
                        key={i}
                        href={segment.href}
                        target="_blank"
                        rel="noopener noreferrer nofollow"
                        // Always underlined, not hover-only: these sit inline in body prose, where a
                        // color-only link fails WCAG 1.4.1 (axe link-in-text-block).
                        className="break-all text-link underline"
                    >
                        {segment.visible}
                    </a>
                ) : (
                    <Fragment key={i}>{segment.value}</Fragment>
                ),
            )}
        </>
    );
}
