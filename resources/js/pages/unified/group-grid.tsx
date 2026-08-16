import { Link } from '@inertiajs/react';

export interface HomeGroup {
    id: number;
    name: string;
    imageUrl: string | null;
    href: string;
}

/**
 * The groups the viewer belongs to, as cover cards. The name lies over the picture rather than under
 * it: a group is recognised by its cover long before it is read, and the label is there to settle the
 * ones that look alike.
 *
 * The scrim is spelled as one arbitrary gradient because the palette guard (RawPaletteGuardTest)
 * admits no raw color-stop utility, and a flat strip over a photo hides either too much of it or too
 * little of the text. `text-scrim-foreground` is the one token that stays white in both themes,
 * because what it sits on is an uploaded picture rather than a surface.
 */
export function GroupGrid({ groups }: { groups: HomeGroup[] }) {
    return (
        <ul className="grid grid-cols-3 gap-2">
            {groups.map((group) => (
                <li key={group.id}>
                    <Link
                        href={group.href}
                        className="relative block aspect-[3/2] overflow-hidden rounded-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        {group.imageUrl ? (
                            // Decorative: the name below is the link's own text.
                            <img src={group.imageUrl} alt="" loading="lazy" className="h-full w-full object-cover" />
                        ) : (
                            <span aria-hidden className="block h-full w-full bg-muted" />
                        )}
                        <span
                            aria-hidden
                            className="absolute inset-x-0 bottom-0 block h-2/3 bg-[linear-gradient(to_top,oklch(0_0_0/0.72),transparent)]"
                        />
                        <span className="absolute inset-x-0 bottom-0 line-clamp-2 px-2 py-1.5 text-xs text-scrim-foreground">{group.name}</span>
                    </Link>
                </li>
            ))}
        </ul>
    );
}
