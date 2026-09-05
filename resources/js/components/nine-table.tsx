import { Link } from '@inertiajs/react';
import { AiCornerMark } from '@/components/ai-corner-mark';
import { InitialBadge } from '@/components/initial-badge';
import { markedName } from '@/lib/identity-mark';
import { useT } from '@/lib/i18n';
import type { NineTableItem } from '@/types';

/**
 * Items are pre-shuffled server-side and rendered in order. A tile is too narrow for an AiChip beside
 * the name, so an AI account is marked the way a standalone Avatar is: the corner tag, and
 * `markedName` as the link's accessible name and hover title.
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
