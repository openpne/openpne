import { Link } from '@inertiajs/react';
import { InitialBadge } from '@/components/initial-badge';
import type { RightRailItem } from '@/types';

/**
 * OpenPNE 3's nineTable gadget: a 3×3 grid of friend or community thumbnails with names. `round`
 * (friends, like the circular Avatar) vs `square` (communities, like CommunityImage) so a person and
 * a place read differently at a glance. Missing images fall back to a neutral initial badge. Items
 * are pre-shuffled server-side; this renders in order.
 */
export function NineTable({ items, shape }: { items: RightRailItem[]; shape: 'round' | 'square' }) {
    if (items.length === 0) {
        return null;
    }
    const rounded = shape === 'round' ? 'rounded-full' : 'rounded-lg';

    return (
        <ul className="grid grid-cols-3 gap-2">
            {items.map((item) => (
                <li key={item.id}>
                    <Link href={item.href} className="group block text-center" title={item.name}>
                        {item.imageUrl ? (
                            <img
                                src={item.imageUrl}
                                alt=""
                                className={`aspect-square w-full object-cover ${rounded} transition group-hover:opacity-90`}
                            />
                        ) : (
                            <InitialBadge aria-hidden name={item.name} className={`aspect-square w-full text-base ${rounded}`} />
                        )}
                        <p className="mt-1 truncate text-[10px] text-muted-foreground transition group-hover:text-foreground">{item.name}</p>
                    </Link>
                </li>
            ))}
        </ul>
    );
}
