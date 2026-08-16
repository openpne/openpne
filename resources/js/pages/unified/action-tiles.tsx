import { Link, usePage } from '@inertiajs/react';
import { CountPill } from '@/components/count-pill';
import { useT } from '@/lib/i18n';
import { visibleNavSections } from '@/lib/member-chrome';
import type { PageProps } from '@/types';

/**
 * Where the viewer can go next, as tiles. Built from the nav registry rather than a list of its own,
 * so a tile carries the same label, icon and attention badge as its nav entry and a switched-off unit
 * drops both at once. No destination here is invented: this is the nav, laid out to be reached with a
 * thumb.
 */
export function ActionTiles() {
    const t = useT();
    const { enabledFeatures, unread } = usePage<PageProps>().props;

    return (
        <ul className="grid grid-cols-3 gap-2">
            {visibleNavSections(enabledFeatures).map(({ href, icon: Icon, label, badge }) => {
                const count = badge ? (unread?.[badge.count] ?? 0) : 0;

                return (
                    <li key={href}>
                        <Link
                            href={href}
                            className="flex min-h-24 flex-col items-center justify-center gap-2 rounded-xl border border-border px-2 py-3 text-center transition-colors hover:bg-muted/40"
                        >
                            <span className="relative">
                                <Icon className="size-6 text-muted-foreground" strokeWidth={2} aria-hidden />
                                {/* The tile is one link named by its label, so the pill has to say what
                                    its number counts — the wording the nav entry already uses. */}
                                {badge && (
                                    <CountPill
                                        count={count}
                                        label={t(badge.label.key, { count })}
                                        className="absolute -top-2 -right-3"
                                    />
                                )}
                            </span>
                            <span className="break-words text-xs text-foreground">{t(label.key, label.replacements)}</span>
                        </Link>
                    </li>
                );
            })}
        </ul>
    );
}
