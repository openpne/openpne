import { Fragment } from 'react';
import { BodyLink } from '@/components/body-link';
import { linkify } from '@/lib/linkify';

/**
 * Matches the Classic <x-user-text> / App\Support\BodyText output. The parent element supplies
 * `whitespace-pre-wrap`, so newlines render as line breaks.
 */
export function UserText({ text }: { text: string | null | undefined }) {
    return (
        <>
            {linkify(text).map((segment, i) =>
                segment.type === 'url' ? (
                    // Always underlined, not hover-only: these sit inline in body prose, where a
                    // color-only link fails WCAG 1.4.1 (axe link-in-text-block).
                    <BodyLink key={i} href={segment.href} className="break-all text-link underline">
                        {segment.visible}
                    </BodyLink>
                ) : (
                    <Fragment key={i}>{segment.value}</Fragment>
                ),
            )}
        </>
    );
}
