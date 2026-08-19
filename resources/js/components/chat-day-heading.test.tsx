import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { ChatDayHeading } from './chat-day-heading';
import { fakeT } from '@/lib/test-i18n';

// useT reads the Inertia page for its term map, which a component test has no page to give it.
vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

vi.mock('@inertiajs/react', () => ({
    // The heading is computed in the site's clock and locale, which live in shared props.
    usePage: () => ({ props: { locale: 'en', timezone: 'Asia/Tokyo' } }),
}));

afterEach(cleanup);

const renderHeading = (at: string) => render(<ChatDayHeading at={at} />);

test('the heading carries the site day as its machine value', () => {
    // 15:05Z is already the next day in Tokyo — the heading follows the site, not the viewer.
    renderHeading('2026-08-09T15:05:16+00:00');

    expect(screen.getByRole('separator').querySelector('time')?.getAttribute('datetime')).toBe('2026-08-10');
});

test('the label is the whole of what the separator announces', () => {
    renderHeading('2020-03-04T01:00:00+09:00');

    const separator = screen.getByRole('separator');
    // The visible text is hidden from assistive technology, so the name has to say it instead —
    // otherwise the separator announces itself as an unnamed break in the list.
    expect(separator.getAttribute('aria-label')).toBe(separator.textContent);
    expect(separator.textContent).toBe('Wed, March 4, 2020');
});

test('the heading takes no pointer events, so it cannot cover a tap', () => {
    // It lies over the rows. Taking the hover for a `title` would mean taking the tap from whatever is
    // under it, measured as a link at 390px — and at 1280 the chip is not hit-testable anyway.
    const { container } = renderHeading('2026-08-10T09:00:00+09:00');

    expect(container.firstElementChild?.className).toContain('pointer-events-none');
    expect(container.querySelector('[style*="pointer-events"]')).toBeNull();
    expect(container.querySelector('time')?.getAttribute('title')).toBeNull();
});

test('today and yesterday are words, and older days are dates', () => {
    vi.useFakeTimers();
    // 12:00 on the 10th in Tokyo.
    vi.setSystemTime(new Date('2026-08-10T03:00:00+00:00'));

    try {
        renderHeading('2026-08-10T09:00:00+09:00');
        expect(screen.getByRole('separator').textContent).toBe('Today');
        cleanup();

        renderHeading('2026-08-09T09:00:00+09:00');
        expect(screen.getByRole('separator').textContent).toBe('Yesterday');
        cleanup();

        renderHeading('2026-08-08T09:00:00+09:00');
        expect(screen.getByRole('separator').textContent).toBe('Sat, August 8');
    } finally {
        vi.useRealTimers();
    }
});

test('a value that is not a date draws no heading at all', () => {
    // Better a run of rows with no day over them than a separator announcing nonsense.
    renderHeading('');

    expect(screen.queryByRole('separator')).toBeNull();
});
