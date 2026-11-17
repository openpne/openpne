import { Link, usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { bottomNavSections, isSectionActive, NOTIFICATIONS_SECTION } from '@/lib/member-chrome';
import { badgePhrase } from '@/lib/count-phrase';
import { useT } from '@/lib/i18n';
import { pressPrefetch } from '@/lib/press-prefetch';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

/**
 * Members only: a guest (a web-public profile is reachable signed out) has no member nav to
 * shortcut.
 */
export function BottomNav({ hidden }: { hidden?: boolean }) {
    const t = useT();
    const { url, props } = usePage<PageProps>();
    const ref = useRef<HTMLElement>(null);

    // `inert` closes the bar to new focus, but whatever already held it keeps it (and its key
    // handlers) off-screen, so focus is dropped explicitly rather than left to the browser.
    useEffect(() => {
        const active = document.activeElement;
        if (hidden && active instanceof HTMLElement && ref.current?.contains(active)) {
            active.blur();
        }
    }, [hidden]);

    if (!props.auth.user) {
        return null;
    }

    // Query and hash off first: a tab is matched against the pathname alone.
    const path = url.replace(/[?#].*$/, '');

    return (
        <nav
            ref={ref}
            aria-label={t('Navigation')}
            inert={hidden || undefined}
            // Side insets for landscape: the bar spans inset-x-0, so the outer tabs would otherwise
            // fall under the display cutout / corner radius.
            className={cn(
                'fixed inset-x-0 bottom-0 z-20 border-t border-border bg-background/90 pr-safe pb-safe pl-safe backdrop-blur transition-transform duration-200 motion-reduce:transition-none lg:hidden',
                hidden && 'translate-y-full',
            )}
        >
            {/* AppShell reserves this same length as `--modern-bottom-offset`, and a bar taller than
                the space it was given stands over the page's last rows. */}
            <ul className="flex h-[3.625rem] items-stretch">
                <LabeledTabs path={path} />
            </ul>
        </nav>
    );
}

/**
 * A shortcut, not the whole nav: the same tabs stay in the drawer. The one mark is a dot on the
 * notifications tab; the counts live in the drawer (docs/internals/modern-frame.md, "The bottom tabs").
 */
function LabeledTabs({ path }: { path: string }) {
    const t = useT();
    const { props } = usePage<PageProps>();

    return (
        <>
            {bottomNavSections(props.enabledFeatures, props.modernHome).map((section) => {
                const { href, icon: Icon, label, badge } = section;
                const active = isSectionActive(section, path);
                const count = badge ? (props.unread?.[badge.count] ?? 0) : 0;
                const dotted = href === NOTIFICATIONS_SECTION.href && count > 0;

                return (
                    <li key={href} className="flex-1">
                        <Link
                            href={href}
                            {...pressPrefetch(href)}
                            aria-current={active ? 'page' : undefined}
                            className={cn(
                                'flex size-full flex-col items-center justify-center gap-1 transition',
                                active ? 'text-foreground' : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            <span className="relative inline-flex">
                                <Icon className="size-6" strokeWidth={active ? 2.25 : 2} aria-hidden />
                                {dotted && <span aria-hidden className="absolute -top-1 -right-1 size-2 rounded-full bg-selected" />}
                            </span>
                            <span className="max-w-full truncate text-2xs leading-none">{t(label.key, label.replacements)}</span>
                            {/* After the word: the name must read word first. */}
                            {badge && dotted && <span className="sr-only">{badgePhrase(t, badge, count)}</span>}
                        </Link>
                    </li>
                );
            })}
        </>
    );
}
