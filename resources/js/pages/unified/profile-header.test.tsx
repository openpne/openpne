import { cleanup, render } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { fakeT } from '@/lib/test-i18n';
import { ProfileHeader } from './profile-header';

// useT reads the Inertia page for its term map, which a component test has no page to give it.
vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

afterEach(cleanup);

const profile = {
    id: 7,
    name: 'Shirabe',
    avatarUrl: null,
    avatarUrlLarge: null,
    avatarColor: null,
    isAi: false,
    bio: 'the first line\nand a second',
};

/** Asserted on the rendered class rather than on layout, which jsdom does not compute. */
test('a self-introduction is a two-line lead-in unless the page says otherwise', () => {
    const { container } = render(<ProfileHeader profile={profile} />);

    expect(container.querySelector('.line-clamp-2')).not.toBeNull();
});

test('a page that has nothing else to say the subject with shows it whole', () => {
    const { container } = render(<ProfileHeader profile={profile} clampBio={false} />);

    expect(container.querySelector('.line-clamp-2')).toBeNull();
    expect(container.textContent).toContain('and a second');
});

test('an empty self-introduction leaves no room behind it', () => {
    const { container } = render(<ProfileHeader profile={{ ...profile, bio: null }} clampBio={false} />);

    expect(container.textContent).not.toContain('the first line');
    expect(container.querySelectorAll('p')).toHaveLength(0);
});

/** A guest's only picture is the 180px square, which the cover's full-width crop would stretch. */
test('a small picture stands where the initial would, and no cover is drawn from it', () => {
    const { container } = render(<ProfileHeader profile={{ ...profile, avatarUrlSmall: '/cache/small.jpg' }} />);

    expect(container.querySelectorAll('img')).toHaveLength(1);
    const image = container.querySelector('img');
    expect(image?.getAttribute('src')).toBe('/cache/small.jpg');
    expect(image?.className).toContain('size-20');
    expect(image?.hasAttribute('srcset')).toBe(false);
});
