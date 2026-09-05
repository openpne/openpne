import { Link } from '@inertiajs/react';
import { AiCornerMark } from '@/components/ai-corner-mark';
import { InitialBadge } from '@/components/initial-badge';
import { derivedIdentityColor } from './identity-visual';
import { markedName } from '@/lib/identity-mark';
import { useT } from '@/lib/i18n';
import type { NineTableItem } from '@/types';

export interface SeatRow {
    seats: 4 | 5;
    /** Faces seated in it; fewer than `seats` in the last row of a count that does not fill it. */
    filled: number;
}

/**
 * Rows of four and of five over the same width put one row's faces between the other's, which is
 * where the stagger comes from. The last row is left ragged rather than centred: centring it would
 * line the columns back up whenever the count fills two rows evenly.
 */
export function seatRows(count: number): SeatRow[] {
    if (count === 0) {
        return [];
    }
    // Spread over the seats the count fills, so neither four nor five leaves a seat's worth of hole at
    // the end of the only row there is.
    if (count <= 5) {
        return [{ seats: count <= 4 ? 4 : 5, filled: count }];
    }

    const rows: SeatRow[] = [];

    for (let left = count, row = 0; left > 0; row++) {
        const seats = row % 2 === 0 ? 4 : 5;
        rows.push({ seats, filled: Math.min(left, seats) });
        left -= seats;
    }

    return rows;
}

/**
 * One list over one grid of forty columns: a list per row would have a screen reader announce a
 * formation drawn for the eye, and forty is what makes the four-seat row's inset a whole number of
 * columns. The seat, not the face, is the link: a circle hands back the corners of its own target.
 */
export function PeopleGrid({ people }: { people: NineTableItem[] }) {
    const t = useT();
    let seated = 0;

    return (
        <ul className="grid grid-cols-40 gap-y-4 sm:gap-y-6">
            {seatRows(people.length).flatMap(({ seats, filled }) => {
                const inRow = people.slice(seated, seated + filled);
                seated += filled;

                return inRow.map((person, seat) => {
                    const label = markedName(person.name, person.isAi, t);

                    return (
                        <li
                            key={person.id}
                            className={seats === 4 ? `col-span-9${seat === 0 ? ' col-start-3' : ''}` : 'col-span-8'}
                        >
                            <Link
                                href={person.href}
                                // aria-label because no name is drawn: the face is the whole tile, and
                                // the AI marker beside it is aria-hidden (NineTable does the same).
                                aria-label={label}
                                title={label}
                                className="group block focus-visible:outline-none"
                            >
                                {/* A cap over the seat rather than a size, so a viewport too narrow to seat five faces
                                    shrinks them instead of letting the row spill out of its card. */}
                                <span className="relative mx-auto block aspect-square w-full max-w-12 rounded-full group-focus-visible:ring-2 group-focus-visible:ring-ring sm:max-w-18">
                                    {person.imageUrl ? (
                                        <img src={person.imageUrl} alt="" loading="lazy" className="h-full w-full rounded-full object-cover" />
                                    ) : (
                                        <InitialBadge
                                            aria-hidden
                                            name={person.name}
                                            color={person.avatarColor ?? derivedIdentityColor(person.name)}
                                            className="h-full w-full rounded-full text-lg"
                                        />
                                    )}
                                    <AiCornerMark isAi={person.isAi} size="lg" />
                                </span>
                            </Link>
                        </li>
                    );
                });
            })}
        </ul>
    );
}
