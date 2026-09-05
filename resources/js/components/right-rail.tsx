import { Link, useForm, usePage } from '@inertiajs/react';
import { type FormEvent, type ReactNode } from 'react';
import { NineTable } from '@/components/nine-table';
import { SearchSubmitButton } from '@/components/search-submit-button';
import { Heading } from '@/components/ui/heading';
import { Input } from '@/components/ui/input';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

/**
 * Nav chrome, so it lives in the shell rather than on any one page. It carries no groups: the
 * sidebar's room list is the same memberships, ordered by what was last said.
 */
export function RightRail() {
    const t = useT();
    const { auth, rightRail } = usePage<PageProps>().props;

    if (!auth.user || !rightRail) {
        return null;
    }

    const people = rightRail.people;
    const friends = people.kind === 'friends';

    return (
        <aside
            aria-label={t('Right rail')}
            className="sticky top-0 hidden h-dvh w-80 shrink-0 flex-col gap-6 overflow-y-auto border-l border-border px-4 py-4 pt-[calc(1rem+env(safe-area-inset-top))] pb-[calc(1rem+env(safe-area-inset-bottom))] xl:flex"
        >
            <SearchBox />

            {people.items.length > 0 && (
                <RailSection
                    title={friends ? t('%Friends%') : t('Members')}
                    viewAllHref={friends ? '/friend/list' : '/member/search'}
                >
                    <NineTable items={people.items} shape="round" />
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
                <Heading as="h2" variant="minor">
                    {title}
                </Heading>
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
        form.get('/member/search', { preserveScroll: true });
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
            <SearchSubmitButton loading={form.processing} />
        </form>
    );
}
