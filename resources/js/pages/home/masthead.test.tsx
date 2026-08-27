import { cleanup, screen } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { Masthead } from './masthead';
import en from '../../../../lang/en.json';
import ja from '../../../../lang/ja.json';
import { renderWithProviders } from '@/lib/test-render';

const inertia = vi.hoisted(() => ({ page: {} as { props: Record<string, unknown> } }));

// The real dictionaries, not `fakeT`: what this is about is that the dateline reads as a sentence
// about a day in the site's language, and a stub returning the English key would assert nothing
// about it. Keys are English source text, so `en` resolves through the fallback the app relies on;
// replacements are substituted the way Laravel does.
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

test('the ja dateline says what the page is: the day, its weekday, and what happened on it', () => {
    inLocale('ja');
    const { container } = renderWithProviders(<Masthead date="2026-08-27" />);

    const time = container.querySelector('time');
    expect(time?.textContent).toBe('2026年8月27日(木)のできごと');
    // The machine-readable value is the civil date itself — no instant, so no timezone can shift it.
    expect(time?.getAttribute('datetime')).toBe('2026-08-27');
});

test('the en dateline carries the weekday too', () => {
    inLocale('en');
    renderWithProviders(<Masthead date="2026-08-27" />);

    expect(screen.getByText('What happened on Thu, August 27, 2026')).toBeTruthy();
});

test('the dateline is the page heading', () => {
    inLocale('ja');
    renderWithProviders(<Masthead date="2026-08-27" />);

    // The day is what the screen is, so the nameplate is the h1 — the chrome draws none for it.
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('2026年8月27日(木)のできごと');
});
