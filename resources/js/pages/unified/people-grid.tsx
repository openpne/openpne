import { Link } from '@inertiajs/react';
import { AiCornerMark } from '@/components/ai-corner-mark';
import { InitialBadge } from '@/components/initial-badge';
import { derivedIdentityColor } from './identity-visual';
import { markedName } from '@/lib/identity-mark';
import { useT } from '@/lib/i18n';
import type { NineTableItem } from '@/types';

/** Seats per row, from the top down: four, then five, then four again. */
const SEATS = (row: number): number => (row % 2 === 0 ? 4 : 5);

/**
 * How many faces each row of the seat map holds, filled from the top row down.
 *
 * The unevenness is the point. Rows of five and of four spread over nearly the same width put one
 * row's faces between the other's, and that stagger is what keeps a group of people from reading as
 * an assembly photo. It follows from the seat counts alone, so no face is offset by hand and the
 * formation survives any width.
 *
 * The last row is left ragged rather than centred: centring it would line the columns back up
 * whenever the count fills two rows evenly.
 */
export function seatRows(count: number): number[] {
    const rows: number[] = [];

    for (let left = count, row = 0; left > 0; row++) {
        rows.push(Math.min(left, SEATS(row)));
        left -= SEATS(row);
    }

    return rows;
}

/**
 * The people a member or a group keeps company with, as faces in the seat map. Faces alone: the row
 * is decoration — who is around, at a glance — and the roster it links to is where names are read.
 * The name is still the link's accessible name, so nothing is lost to anyone reading the page aloud.
 *
 * A face is the mock's 48px, and 72 in the desktop column; the seat it sits in is what the row's width
 * divides into, so the slack around it grows with the screen rather than the face. The cap is written
 * as a maximum over the seat, not as a size, so a viewport too narrow to seat five 48px faces shrinks
 * them instead of letting the row spill out of its card.
 *
 * The five-seat row spans the section and the four-seat row is spread a little narrower, which is what
 * leaves one row's faces between the other's.
 *
 * The seat, not the face, is the link: a circle would hand back the corners of its own target.
 */
export function PeopleGrid({ people }: { people: NineTableItem[] }) {
    const t = useT();
    let seated = 0;

    return (
        <div className="space-y-4 sm:space-y-6">
            {seatRows(people.length).map((filled, row) => {
                const inRow = people.slice(seated, seated + filled);
                seated += filled;

                return (
                    <ul key={inRow[0]?.id ?? row} className={SEATS(row) === 4 ? 'mx-[4%] grid grid-cols-4' : 'grid grid-cols-5'}>
                        {inRow.map((person) => {
                            const label = markedName(person.name, person.isAi, t);

                            return (
                                <li key={person.id}>
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
                        })}
                    </ul>
                );
            })}
        </div>
    );
}
