import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { LookPreviewBar } from './look-preview-bar';
import { fakeT } from '@/lib/test-i18n';
import type { LookPreview } from '@/types';

// useT reads the Inertia page for its term map, which a component test has no page to give it.
vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

// The bar reads the shared props and leaves by posting; both are stood in for, the router with a
// spy since which route each button posts to is what this file is about.
const inertia = vi.hoisted(() => ({
    page: {} as { props: Record<string, unknown> },
    post: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => inertia.page,
    router: { post: inertia.post },
}));

afterEach(() => {
    cleanup();
    inertia.post.mockClear();
});

function arrive(lookPreview: LookPreview | null) {
    inertia.page = { props: { lookPreview } };
}

test('the bar names the look being tried on and offers both ways out', () => {
    arrive({ look: 'unified', pin: true, label: 'Unified (experimental)' });

    render(<LookPreviewBar />);

    expect(screen.getByRole('region', { name: 'Layout preview' })).toBeTruthy();
    expect(screen.getByText('Previewing: Unified (experimental)')).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: 'Use this layout' }));
    expect(inertia.post).toHaveBeenCalledWith('/member/config/look');

    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(inertia.post).toHaveBeenCalledWith('/member/config/look/preview/stop');
});

test('the confirm POST carries no choice, so it cannot disagree with the bar', () => {
    // The session is the intent; a field here would be a second copy of it.
    arrive({ look: 'standard', pin: false, label: 'Standard' });

    render(<LookPreviewBar />);
    fireEvent.click(screen.getByRole('button', { name: 'Use this layout' }));

    expect(inertia.post).toHaveBeenCalledWith('/member/config/look');
});

test('nothing is drawn while no look is being tried on', () => {
    arrive(null);

    const { container } = render(<LookPreviewBar />);

    expect(container.innerHTML).toBe('');
});
