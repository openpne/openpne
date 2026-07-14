import { Link } from '@inertiajs/react';
import { InitialBadge } from '@/components/initial-badge';
import type { NineTableItem } from '@/types';

/**
 * A grid of friend or community thumbnails with names. `round`
 * (friends, like the circular Avatar) vs `square` (communities, like CommunityImage) so a person and
 * a place read differently at a glance. Missing images fall back to a neutral initial badge. Items
 * are pre-shuffled server-side; this renders in order. `columns` picks the density: 3 fits the narrow
 * right rail; 5 keeps roughly the same tile size in a full-width body column (3 on mobile either way).
 */
export function NineTable({ items, shape, columns = 3 }: { items: NineTableItem[]; shape: 'round' | 'square'; columns?: 3 | 5 }) {
    if (items.length === 0) {
        return null;
    }
    const rounded = shape === 'round' ? 'rounded-full' : 'rounded-lg';

    return (
        <ul className={columns === 5 ? 'grid grid-cols-3 gap-2 sm:grid-cols-5' : 'grid grid-cols-3 gap-2'}>
            {items.map((item) => (
                <li key={item.id}>
                    <Link href={item.href} className="group block text-center" title={item.name}>
                        {item.imageUrl ? (
                            <img
                                src={item.imageUrl}
                                alt=""
                                loading="lazy"
                                className={`aspect-square w-full object-cover ${rounded} transition group-hover:opacity-90`}
                            />
                        ) : (
                            <InitialBadge
                                aria-hidden
                                name={item.name}
                                // Chosen colors are a member feature; a community tile stays neutral even
                                // if a colored item ever leaks into the square grid.
                                color={shape === 'round' ? item.avatarColor : null}
                                className={`aspect-square w-full text-base ${rounded}`}
                            />
                        )}
                        <p className="mt-1 truncate text-[10px] text-muted-foreground transition group-hover:text-foreground">{item.name}</p>
                    </Link>
                </li>
            ))}
        </ul>
    );
}
