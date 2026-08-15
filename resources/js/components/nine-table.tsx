import { Link } from '@inertiajs/react';
import { AiCornerMark } from '@/components/ai-corner-mark';
import { InitialBadge } from '@/components/initial-badge';
import { markedName } from '@/lib/identity-mark';
import { useT } from '@/lib/i18n';
import type { NineTableItem } from '@/types';

/**
 * A grid of friend or group thumbnails with names. `round`
 * (friends, like the circular Avatar) vs `square` (groups, like CommunityImage) so a person and
 * a place read differently at a glance. Missing images fall back to a neutral initial badge. Items
 * are pre-shuffled server-side; this renders in order. `columns` picks the density: 3 fits the narrow
 * right rail; 5 keeps roughly the same tile size in a full-width body column (3 on mobile either way).
 * A 5-column caller sends 10 items so a full grid makes two clean rows; mobile trims to 9 (3×3)
 * rather than leave a one-tile fourth row.
 *
 * A tile is too narrow for an AiChip beside the name — the name itself is one truncated line — so an
 * AI account is marked the way a standalone Avatar is: the corner tag visually, and `markedName` as
 * the link's accessible name and hover title. Group tiles arrive with `isAi` false and are unaffected.
 */
export function NineTable({ items, shape, columns = 3 }: { items: NineTableItem[]; shape: 'round' | 'square'; columns?: 3 | 5 }) {
    const t = useT();

    if (items.length === 0) {
        return null;
    }
    const rounded = shape === 'round' ? 'rounded-full' : 'rounded-lg';

    return (
        <ul className={columns === 5 ? 'grid grid-cols-3 gap-2 sm:grid-cols-5' : 'grid grid-cols-3 gap-2'}>
            {items.map((item, index) => {
                const label = markedName(item.name, item.isAi, t);

                return (
                    <li key={item.id} className={columns === 5 && index >= 9 ? 'hidden sm:block' : undefined}>
                        {/* aria-label rather than the tile's own text: the name below the face is the
                            visible half, and letting it name the link would drop the marker. */}
                        <Link href={item.href} aria-label={label} className="group block text-center" title={label}>
                            <span className="relative block">
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
                                        // Chosen colors are a member feature; a group tile stays neutral even
                                        // if a colored item ever leaks into the square grid.
                                        color={shape === 'round' ? item.avatarColor : null}
                                        className={`aspect-square w-full text-base ${rounded}`}
                                    />
                                )}
                                <AiCornerMark isAi={item.isAi} size="lg" />
                            </span>
                            <p className="mt-1 truncate text-[10px] text-muted-foreground transition group-hover:text-foreground">{item.name}</p>
                        </Link>
                    </li>
                );
            })}
        </ul>
    );
}
