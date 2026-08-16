import { Link } from '@inertiajs/react';
import { AiCornerMark } from '@/components/ai-corner-mark';
import { InitialBadge } from '@/components/initial-badge';
import { markedName } from '@/lib/identity-mark';
import { useT } from '@/lib/i18n';
import type { NineTableItem } from '@/types';

/**
 * The people the viewer keeps company with, as faces alone. No names: a wrapped row of them is read
 * as a crowd the viewer recognises, and printing ten labels under it turns a glance into a list. The
 * name is still the link's accessible name, so nothing is lost to anyone reading it aloud.
 *
 * Larger than the Avatar component's cap (48px), which is why this draws the face itself.
 */
export function PeopleRow({ people }: { people: NineTableItem[] }) {
    const t = useT();

    return (
        <ul className="flex flex-wrap gap-3">
            {people.map((person) => {
                const label = markedName(person.name, person.isAi, t);

                return (
                    <li key={person.id}>
                        <Link
                            href={person.href}
                            aria-label={label}
                            title={label}
                            className="relative block size-16 rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            {person.imageUrl ? (
                                <img src={person.imageUrl} alt="" loading="lazy" className="size-16 rounded-full object-cover" />
                            ) : (
                                <InitialBadge aria-hidden name={person.name} color={person.avatarColor} className="size-16 rounded-full text-lg" />
                            )}
                            <AiCornerMark isAi={person.isAi} size="lg" />
                        </Link>
                    </li>
                );
            })}
        </ul>
    );
}
