import { cleanup, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { MemberFrame } from './member-frame';
import { fakeT } from '@/lib/test-i18n';
import { resolveChrome } from '@/lib/member-chrome';
import type { AuthUser, FeatureKey } from '@/types';

// useT reads the Inertia page for its term map, which a component test has no page to give it.
vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

const inertia = vi.hoisted(() => ({ page: {} as { props: Record<string, unknown> } }));

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

/** A hub, framed for the given viewer and layout. Returns the heading row the fold acts on. */
function hubHeadingRow(props: Record<string, unknown>): Element | null {
    inertia.page = {
        props: {
            auth: { user },
            enabledFeatures: allOn,
            unifiedLayout: false,
            unread: null,
            flash: { status: null, error: null },
            ...props,
        },
    };
    const chrome = resolveChrome('notifications/index', inertia.page.props);

    render(<MemberFrame chrome={chrome}>{null}</MemberFrame>);

    return screen.getByRole('heading', { name: 'Notifications' }).parentElement;
}

/**
 * The fold is a claim that the mobile bar is showing the title instead. Three bars stand over a hub
 * and only one of them does: the shipped hub bar. A guest's is brand + sign-in, and the unified
 * layout's is the tab pair — fold under either and the hub has a visible name nowhere on a phone.
 */
test('a hub folds its heading under the bar that carries the title', () => {
    expect(hubHeadingRow({})?.className).toContain('max-lg:sr-only');
});

test('a hub keeps its heading under the unified bar, which carries tabs instead', () => {
    expect(hubHeadingRow({ unifiedLayout: true })?.className).not.toContain('sr-only');
});

test('a hub keeps its heading for a guest, whose bar carries no title either', () => {
    expect(hubHeadingRow({ auth: { user: null } })?.className).not.toContain('sr-only');
});
