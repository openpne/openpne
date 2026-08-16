import { Link } from '@inertiajs/react';
import { AiCornerMark } from '@/components/ai-corner-mark';
import { InitialBadge } from '@/components/initial-badge';
import { derivedIdentityColor } from './identity-visual';
import { markedName } from '@/lib/identity-mark';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { NineTableItem } from '@/types';

/**
 * The people a member keeps company with, as faces. On the home, faces alone: a wrapped row of them
 * is read as a crowd the viewer recognises, and printing ten labels under it turns a glance into a
 * list. The name is still the link's accessible name, so nothing is lost to anyone reading it aloud.
 *
 * `named` prints it under the face as well, for a page about somebody else: there the row answers
 * "who is this member close to", which is a question about names rather than about faces the reader
 * already knows.
 *
 * Larger than the Avatar component's cap (48px), which is why this draws the face itself.
 */
export function PeopleRow({ people, named }: { people: NineTableItem[]; named?: boolean }) {
    const t = useT();

    return (
        <ul className="flex flex-wrap gap-3">
            {people.map((person) => {
                const label = markedName(person.name, person.isAi, t);

                return (
                    <li key={person.id}>
                        <Link
                            href={person.href}
                            // aria-label rather than the name below the face: letting the visible half
                            // name the link would drop the AI marker (NineTable does the same).
                            aria-label={label}
                            title={label}
                            className={cn(
                                'group block focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                named ? 'w-16 rounded-lg' : 'rounded-full',
                            )}
                        >
                            <span className="relative block size-18">
                                {person.imageUrl ? (
                                    <img src={person.imageUrl} alt="" loading="lazy" className="size-18 rounded-full object-cover" />
                                ) : (
                                    <InitialBadge
                                        aria-hidden
                                        name={person.name}
                                        color={person.avatarColor ?? derivedIdentityColor(person.name)}
                                        className="size-18 rounded-full text-lg"
                                    />
                                )}
                                <AiCornerMark isAi={person.isAi} size="lg" />
                            </span>
                            {named && (
                                <span className="mt-1 block truncate text-center text-xs text-muted-foreground transition group-hover:text-foreground">
                                    {person.name}
                                </span>
                            )}
                        </Link>
                    </li>
                );
            })}
        </ul>
    );
}
