import { Link } from '@inertiajs/react';
import { AiCornerMark } from '@/components/ai-corner-mark';
import { InitialBadge } from '@/components/initial-badge';
import { derivedIdentityColor } from './identity-visual';
import { markedName } from '@/lib/identity-mark';
import { useT } from '@/lib/i18n';
import type { NineTableItem } from '@/types';

/** A row of the seat map: how wide the seats are spread, and how many of them are taken. */
export interface SeatRow {
    /** Seats the row is spread over — four or five, which is what sets the pitch between faces. */
    seats: 4 | 5;
    /** Faces seated in it; fewer than `seats` in the last row of a count that does not fill it. */
    filled: number;
}

/**
 * The rows of the seat map: four faces spread over the row, then five, then four again.
 *
 * The unevenness is the point. Rows of four and of five over the same width put one row's faces
 * between the other's, and that stagger is what keeps a group of people from reading as an assembly
 * photo. It follows from the seat counts alone, so no face is offset by hand and the formation
 * survives any width.
 *
 * The last row is left ragged rather than centred: centring it would line the columns back up
 * whenever the count fills two rows evenly. A count that fits one row is drawn as one row, though —
 * a second row holding a single face has nothing to stagger against, and reads as a leftover.
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
 * The people a member or a group keeps company with, as faces in the seat map. Faces alone: the grid
 * is decoration — who is around, at a glance — and the roster it links to is where names are read.
 * The name is still the link's accessible name, so nothing is lost to anyone reading the page aloud.
 *
 * A face is the mock's 48px, and 72 in the desktop column; the seat it sits in is what the width
 * divides into, so the slack around it grows with the screen rather than the face. The cap is written
 * as a maximum over the seat, not as a size, so a viewport too narrow to seat five 48px faces shrinks
 * them instead of letting the row spill out of its card.
 *
 * One list over one grid of forty columns: the rows are a visual arrangement, and a list per row would
 * have a screen reader announce a formation the design draws for the eye. A seat is eight columns in a
 * row of five, and nine starting two columns in for a row of four — which is where the stagger comes
 * from, since the four-seat row is both wider-pitched and held off the edges. Forty columns is what
 * lets that inset be a whole number of them. The top row is always full, so what breaks a row is the
 * spans rather than the count.
 *
 * The seat, not the face, is the link: a circle would hand back the corners of its own target.
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
