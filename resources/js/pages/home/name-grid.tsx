import { Link } from '@inertiajs/react';
import { Avatar } from '@/components/avatar';
import { CommunityImage } from '@/components/community-image';
import type { HomeGroup } from '@/components/sections/group-grid';
import { markedName } from '@/lib/identity-mark';
import { useT } from '@/lib/i18n';
import type { PersonTile } from '@/types';

const GRID = 'grid grid-cols-2 gap-x-3.5';
const CELL = 'flex min-h-12 min-w-0 items-center gap-2.5 text-base text-foreground hover:underline';

/** The AI mark on the face is aria-hidden, so the link's name carries the fact instead. */
export function PeopleNames({ people }: { people: PersonTile[] }) {
    const t = useT();

    return (
        <ul className={GRID}>
            {people.map((person) => (
                <li key={person.id} className="min-w-0">
                    <Link href={person.href} aria-label={markedName(person.name, person.isAi, t)} className={CELL}>
                        <Avatar
                            id={person.id}
                            name={person.name}
                            src={person.imageUrl}
                            color={person.avatarColor}
                            isAi={person.isAi}
                            size="sm"
                            decorative
                        />
                        <span className="truncate">{person.name}</span>
                    </Link>
                </li>
            ))}
        </ul>
    );
}

export function GroupNames({ groups }: { groups: HomeGroup[] }) {
    return (
        <ul className={GRID}>
            {groups.map((group) => (
                <li key={group.id} className="min-w-0">
                    <Link href={group.href} className={CELL}>
                        <CommunityImage name={group.name} src={group.imageUrl} className="size-8" textClassName="text-xs" decorative />
                        <span className="truncate font-serif">{group.name}</span>
                    </Link>
                </li>
            ))}
        </ul>
    );
}
