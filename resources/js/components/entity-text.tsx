import { Link } from '@inertiajs/react';
import { UserText } from '@/components/user-text';
import { splitEntities, type MentionEntity, type TagEntity } from '@/lib/entity-split';

/**
 * Renders a plain-text body whose @mention ranges link to the member they name and whose #hashtag
 * ranges link to that tag's page, matching the Classic <x-timeline-body> / App\Support\EntityText
 * output. Text outside them renders through <UserText>, so the two paths cannot drift in how a
 * body's URLs and line breaks look.
 */
export function EntityText({
    text,
    mentions,
    tags = [],
}: {
    text: string | null | undefined;
    mentions: MentionEntity[];
    tags?: TagEntity[];
}) {
    return (
        <>
            {splitEntities(text, mentions, tags).map((segment, i) => {
                // Underlined for the same reason <UserText> underlines a URL: inline in body prose, a
                // color-only link fails WCAG 1.4.1 (axe link-in-text-block).
                if (segment.kind === 'mention') {
                    return (
                        <Link key={i} href={`/member/${segment.memberId}`} className="text-link underline">
                            {segment.text}
                        </Link>
                    );
                }
                if (segment.kind === 'hashtag') {
                    return (
                        <Link key={i} href={`/timeline/tag/${encodeURIComponent(segment.tag)}`} className="text-link underline">
                            {segment.text}
                        </Link>
                    );
                }
                return <UserText key={i} text={segment.text} />;
            })}
        </>
    );
}
