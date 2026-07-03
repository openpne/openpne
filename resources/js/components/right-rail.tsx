import { Link, useForm, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { type FormEvent, type ReactNode } from 'react';
import { NineTable } from '@/components/nine-table';
import { Input } from '@/components/ui/input';
import { useT } from '@/lib/i18n';
import type { PageProps, RightRailItem } from '@/types';

/**
 * Desktop-only (xl+) right rail: a member search box plus friends and joined-community thumbnail
 * grids (OpenPNE 3's sidemenu nineTables, brought back as a PC enhancement). Hidden below xl and for
 * guests; mobile reaches the same lists through the nav. The rail is nav chrome, so it lives in the
 * shell, not on any one page.
 */
export function RightRail() {
    const t = useT();
    const { auth, rightRail } = usePage<PageProps>().props;

    if (!auth.user || !rightRail) {
        return null;
    }

    return (
        <aside
            aria-label={t('Right rail')}
            className="sticky top-0 hidden h-dvh w-80 shrink-0 flex-col gap-6 overflow-y-auto border-l border-border px-4 py-4 xl:flex"
        >
            <SearchBox />

            {rightRail.friends.length > 0 && (
                <RailSection title={t('%Friends%')} viewAllHref="/m/friend/list">
                    <NineTable items={rightRail.friends} shape="round" />
                </RailSection>
            )}

            {rightRail.joinedCommunities.length > 0 && (
                <RailSection title={t('Joined %communities%')} viewAllHref="/m/community/joined">
                    <NineTable items={rightRail.joinedCommunities} shape="square" />
                </RailSection>
            )}
        </aside>
    );
}

function RailSection({ title, viewAllHref, children }: { title: string; viewAllHref: string; children: ReactNode }) {
    const t = useT();
    return (
        <section>
            <div className="mb-2 flex items-baseline justify-between gap-2">
                <h2 className="text-sm font-semibold text-foreground">{title}</h2>
                <Link href={viewAllHref} className="text-xs text-link hover:underline">
                    {t('View all')}
                </Link>
            </div>
            {children}
        </section>
    );
}

function SearchBox() {
    const t = useT();
    // Member search filters by ?name= (see MemberSearchController), not ?keyword= like the diary feed.
    const form = useForm({ name: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (form.data.name.trim() === '') {
            return;
        }
        form.get('/m/member/search', { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} role="search" className="relative">
            <label htmlFor="right_rail_search" className="sr-only">
                {t('Search members')}
            </label>
            <Input
                id="right_rail_search"
                type="search"
                enterKeyHint="search"
                placeholder={t('Search members')}
                value={form.data.name}
                onChange={(e) => form.setData('name', e.target.value)}
                className="rounded-full pr-11 pl-5"
            />
            <button
                type="submit"
                aria-label={t('Search')}
                className="absolute top-1/2 right-1.5 flex size-9 -translate-y-1/2 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
                <Search className="size-4" aria-hidden />
            </button>
        </form>
    );
}
