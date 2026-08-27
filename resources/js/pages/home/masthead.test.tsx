import { cleanup, screen } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { Masthead } from './masthead';
import en from '../../../../lang/en.json';
import ja from '../../../../lang/ja.json';
import { renderWithProviders } from '@/lib/test-render';

const inertia = vi.hoisted(() => ({ page: {} as { props: Record<string, unknown> } }));

// The real dictionaries, not `fakeT`: what this is about is that the dateline reads as a date and an
// issue number in the site's language, and a stub returning the English key would assert nothing
// about either. Keys are English source text, so `en` resolves through the fallback the app relies
// on; replacements are substituted the way Laravel does.
const dictionaries: Record<string, Record<string, string>> = { en, ja };
const translate = (key: string, replacements: Record<string, string | number> = {}): string =>
    Object.entries(replacements).reduce(
        (line, [name, value]) => line.replaceAll(`:${name}`, String(value)),
        dictionaries[String(inertia.page.props.locale)]?.[key] ?? key,
    );

vi.mock('@/lib/i18n', () => ({ useT: () => translate }));
vi.mock('@inertiajs/react', () => ({ usePage: () => inertia.page }));

afterEach(cleanup);

/** The site's clock and language, which are what the dateline is rendered in. */
function inLocale(locale: string) {
    inertia.page = { props: { locale, timezone: 'Asia/Tokyo' } };
}

test('the ja dateline names the day, the weekday and the issue', () => {
    inLocale('ja');
    const { container } = renderWithProviders(<Masthead date="2026-08-27" number={12} />);

    expect(screen.getByText('2026年8月27日(木)')).toBeTruthy();
    expect(screen.getByText('第12号')).toBeTruthy();
    // The machine-readable value is the civil date itself — no instant, so no timezone can shift it.
    expect(container.querySelector('time')?.getAttribute('datetime')).toBe('2026-08-27');
});

test('the en dateline carries the weekday too', () => {
    inLocale('en');
    renderWithProviders(<Masthead date="2026-08-27" number={12} />);

    expect(screen.getByText('Thu, August 27, 2026')).toBeTruthy();
    expect(screen.getByText('No. 12')).toBeTruthy();
});

test('a site with no issue yet gets the date alone', () => {
    // Nothing has been published, so there is no number to print — and printing a zero would name an
    // issue that does not exist.
    inLocale('ja');
    renderWithProviders(<Masthead date="2026-08-27" />);

    expect(screen.getByText('2026年8月27日(木)')).toBeTruthy();
    expect(screen.queryByText(/号$/)).toBeNull();
});

test('the dateline is the page heading', () => {
    inLocale('ja');
    renderWithProviders(<Masthead date="2026-08-27" number={12} />);

    // The issue is what the screen is, so the nameplate is the h1 — the chrome draws none for it.
    expect(screen.getByRole('heading', { level: 1 }).textContent).toContain('第12号');
});
