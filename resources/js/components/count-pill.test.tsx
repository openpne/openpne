import { readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { cleanup, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { BottomNav } from './bottom-nav';
import { CountPill } from './count-pill';
import { NavItems } from './nav-items';
import { PageTabs } from './page-tabs';
import { ConversationRow } from '@/pages/message/conversations/conversation-row';
import { RoomRow } from '@/pages/community/room-row';
import { HomeSection } from '@/pages/unified/home-section';
import { fakeT } from '@/lib/test-i18n';
import { type Chrome, resolveChrome } from '@/lib/member-chrome';
import type { AuthUser, FeatureKey } from '@/types';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

const inertia = vi.hoisted(() => ({ page: {} as { component: string; url: string; props: Record<string, unknown> } }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => inertia.page,
    Link: ({ href, children, ...rest }: { href: string; children?: ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

afterEach(cleanup);

const allOn = Object.fromEntries(
    ['diary', 'directMessage', 'timeline', 'group', 'groupTopic', 'groupEvent', 'groupTalk', 'friend', 'mcp'].map((unit) => [unit, true]),
) as Record<FeatureKey, boolean>;

const user: AuthUser = { id: 1, name: 'Viewer', email: 'viewer@example.com', imageUrl: null, avatarColor: null, isAi: false };

function arrive(component: string, url: string, props: Record<string, unknown> = {}): Chrome {
    inertia.page = {
        component,
        url,
        props: {
            auth: { user },
            enabledFeatures: allOn,
            look: 'standard',
            unread: { friendRequests: 0, unreadMessages: 0, notifications: 0, groupTalks: 0 },
            ...props,
        },
    };

    return resolveChrome(component, inertia.page.props);
}

/** Asserts each name exists *exactly* — `name` as a string is an exact match, so a phrase that
 *  moved, doubled or lost a word fails here rather than passing on a substring. */
const named = (...names: string[]): void => {
    for (const name of names) {
        expect(screen.getByRole('link', { name })).toBeTruthy();
    }
};

const counted = { friendRequests: 4, unreadMessages: 5, notifications: 2, groupTalks: 3 };

/*
 * The orders differ deliberately: the bar's tab puts the pill ahead of the visible word in the DOM,
 * so its name leads with the count.
 */
test('the bar tab is named count first, as it was', () => {
    render(<BottomNav chrome={arrive('dashboard', '/dashboard', { unread: counted })} />);

    named('3 %communities% with new messages %Communities%', '2 unread notifications Notifications');
});

test('a nav entry is named word first, as it was', () => {
    arrive('dashboard', '/dashboard', { unread: counted });
    render(<NavItems />);

    named(
        '%Communities% 3 %communities% with new messages',
        '%Friends% 4 pending %friend% requests',
        'Messages 5 conversations with new messages',
        'Notifications 2 unread notifications',
    );
});

test('a count of one is said in the singular, on every phrase the nav carries', () => {
    arrive('dashboard', '/dashboard', { unread: { friendRequests: 1, unreadMessages: 1, notifications: 1, groupTalks: 1 } });
    render(<NavItems />);

    named(
        '%Communities% 1 %community% with new messages',
        '%Friends% 1 pending %friend% request',
        'Messages 1 conversation with new messages',
        'Notifications 1 unread notification',
    );
});

test('the bar tab says one in the singular too', () => {
    const unread = { friendRequests: 1, unreadMessages: 1, notifications: 1, groupTalks: 1 };
    render(<BottomNav chrome={arrive('dashboard', '/dashboard', { unread })} />);

    named('1 %community% with new messages %Communities%', '1 unread notification Notifications');
});

test('a hub tab is named word first, as it was', () => {
    render(
        <PageTabs
            ariaLabel="Views"
            items={[{ href: '/groups/mine', label: 'Joined', active: true, count: 3, countLabel: '3 %communities% with new messages' }]}
        />,
    );

    named('Joined 3 %communities% with new messages');
});

/*
 * The attribute and the phrase fold into the same names, so the guard against an `aria-label` on the
 * pill has to look at the markup itself.
 */
test('the pill names no role-less element of its own', () => {
    const { container } = render(<CountPill count={3} label="3 unread messages" />);

    expect(container.querySelectorAll('[aria-label]')).toHaveLength(0);
    // And the digits stay out of the name, so nothing is announced twice.
    expect(container.querySelector('[aria-hidden]')?.textContent).toBe('3');
});

test('the pill with nothing to say adds nothing to a name', () => {
    render(
        <a href="/x">
            Somewhere
            <CountPill count={3} />
        </a>,
    );

    named('Somewhere');
    expect(screen.queryByRole('link', { name: 'Somewhere 3' })).toBeNull();
});

/*
 * Shape B: the pill sits in the row's other column, outside the stretched link, so the phrase has to
 * be inside the link or it names nothing at all — which is what this row did before.
 */
test('a conversation row is named by who it is with and how many are waiting', () => {
    render(
        <ConversationRow
            conversation={{
                counterpart: { id: 2, name: 'Sato', imageUrl: null, avatarColor: null, isAi: false },
                unread: 9,
                latest: { body: 'see you there', createdAt: '2026-08-20T10:00:00+09:00' },
            }}
        />,
    );

    named('Sato 9 unread messages');
});

test('a conversation row with one waiting says so in the singular', () => {
    render(
        <ConversationRow
            conversation={{
                counterpart: { id: 2, name: 'Sato', imageUrl: null, avatarColor: null, isAi: false },
                unread: 1,
                latest: { body: 'see you there', createdAt: '2026-08-20T10:00:00+09:00' },
            }}
        />,
    );

    named('Sato 1 unread message');
});

test('a conversation row with nothing waiting is named by who it is with, and no more', () => {
    render(
        <ConversationRow
            conversation={{
                counterpart: { id: 2, name: 'Sato', imageUrl: null, avatarColor: null, isAi: false },
                unread: 0,
                latest: { body: 'see you there', createdAt: '2026-08-20T10:00:00+09:00' },
            }}
        />,
    );

    named('Sato');
});

/* No control at all beside the pill, so the heading is the only thing that can hold the count. */
test('a section heading takes the count the pill beside it cannot name', () => {
    render(
        <HomeSection
            title={
                <>
                    Talk
                    <span className="sr-only"> 3 unread messages</span>
                </>
            }
            right={<CountPill count={3} />}
        >
            <p>body</p>
        </HomeSection>,
    );

    expect(screen.getByRole('heading', { name: 'Talk 3 unread messages' })).toBeTruthy();
});

test('a section heading with nothing waiting says only what it is', () => {
    render(
        <HomeSection title={<>Talk</>} right={<CountPill count={0} />}>
            <p>body</p>
        </HomeSection>,
    );

    expect(screen.getByRole('heading', { name: 'Talk' })).toBeTruthy();
});

/*
 * A call site that forgets the replacement announces the placeholder itself, and nothing renders
 * every call site, so this reads them instead.
 */
test('every unread phrase is given the count it names', () => {
    const root = path.join(import.meta.dirname, '..');
    const sources = (dir: string): string[] =>
        readdirSync(dir, { withFileTypes: true }).flatMap((e) => {
            const full = path.join(dir, e.name);
            if (e.isDirectory()) return sources(full);

            return /\.tsx?$/.test(e.name) && !/\.test\.tsx?$/.test(e.name) ? [full] : [];
        });

    const calls = sources(root).flatMap((file) => {
        const code = readFileSync(file, 'utf8');
        // `lib/member-chrome.ts` has a local `t` building a deferred label whose calls carry no count,
        // so only the translator's own calls are read.
        if (!code.includes("from '@/lib/i18n'")) return [];
        // Anywhere in the key, not only at its start: `Jump to :count unread messages` and
        // `%Friends% (:count)` are the same template and were sailing past a `^:count` match.
        const found = code.match(/t\('[^']*:count[^']*'[^)]*\)/g) ?? [];

        // The replacement object, not the word: `:count` is itself in every match, which is how the
        // first version of this passed the very call site it was written for.
        return found.filter((call) => !/',\s*\{/.test(call)).map((call) => `${path.relative(root, file)}: ${call}`);
    });
    // Non-empty haystack: the regex finding nothing at all would pass this the same way.
    expect(sources(root).length).toBeGreaterThan(50);
    expect(calls).toEqual([]);
});

test('a group room row is named by the group and how many are waiting', () => {
    render(
        <RoomRow
            room={{
                id: 3,
                name: 'Book club',
                imageUrl: null,
                unread: 4,
                muted: false,
                latest: { body: 'see you there', authorName: 'Sato', authorIsAi: false, createdAt: '2026-08-20T10:00:00+09:00' },
            }}
        />,
    );

    named('Book club 4 unread messages');
});

test('a group room row with one waiting says so in the singular', () => {
    render(
        <RoomRow
            room={{
                id: 3,
                name: 'Book club',
                imageUrl: null,
                unread: 1,
                muted: false,
                latest: { body: 'see you there', authorName: 'Sato', authorIsAi: false, createdAt: '2026-08-20T10:00:00+09:00' },
            }}
        />,
    );

    named('Book club 1 unread message');
});

/*
 * The heading tests above prove the mechanism and not the wiring — either page could drop its
 * `sr-only` phrase and stay green — so this reads the pages instead.
 */
test('every heading that shows a pill also says the count', () => {
    const root = path.join(import.meta.dirname, '..');
    const pages = (dir: string): string[] =>
        readdirSync(dir, { withFileTypes: true }).flatMap((e) => {
            const full = path.join(dir, e.name);
            if (e.isDirectory()) return pages(full);

            return /\.tsx$/.test(e.name) && !/\.test\.tsx$/.test(e.name) ? [full] : [];
        });

    const PILL_IN_HEADER = /right=\{<CountPill/g;
    const SECTION = /<(?:Panel|HomeSection)\b/g;

    // Every such heading, not one per file: counting files would leave a second one in the same page
    // unexamined, and the count below would not move.
    const sites = pages(root).flatMap((file) => {
        const code = readFileSync(file, 'utf8');

        return [...code.matchAll(PILL_IN_HEADER)].map((m) => ({ file, code, at: m.index ?? 0 }));
    });
    // Two headings use this shape today; a scan finding none would pass on an empty set.
    expect(sites).toHaveLength(2);

    const bare = sites.filter(({ code, at }) => {
        // Scoped to this pill's own section: "an sr-only somewhere near a title" would be satisfied
        // by any other section's.
        const opens = [...code.slice(0, at).matchAll(SECTION)];

        return !/sr-only[\s\S]*?(?::count|unreadMessagesPhrase\()/.test(code.slice(opens.at(-1)?.index ?? 0, at));
    });
    expect(bare.map(({ file }) => path.relative(root, file))).toEqual([]);
});
