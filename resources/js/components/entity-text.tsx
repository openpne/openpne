import { Link } from '@inertiajs/react';
import { UserText } from '@/components/user-text';
import { splitEntities, type MentionEntity } from '@/lib/entity-split';

/**
 * Renders a plain-text body whose @mention ranges link to the member they name, matching the
 * Classic <x-timeline-body> / App\Support\EntityText output. Text outside the mentions renders
 * through <UserText>, so the two paths cannot drift in how a body's URLs and line breaks look.
 */
export function EntityText({ text, mentions }: { text: string | null | undefined; mentions: MentionEntity[] }) {
    return (
        <>
            {splitEntities(text, mentions).map((segment, i) =>
                segment.kind === 'mention' ? (
                    <Link
                        key={i}
                        href={`/member/${segment.memberId}`}
                        // Underlined for the same reason <UserText> underlines a URL: inline in body
                        // prose, a color-only link fails WCAG 1.4.1 (axe link-in-text-block).
                        className="text-link underline"
                    >
                        {segment.text}
                    </Link>
                ) : (
                    <UserText key={i} text={segment.text} />
                ),
            )}
        </>
    );
}
