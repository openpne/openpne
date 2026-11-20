import { act, cleanup, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { Masthead } from './masthead';
import en from '../../../../lang/en.json';
import ja from '../../../../lang/ja.json';
import { renderWithProviders } from '@/lib/test-render';

const inertia = vi.hoisted(() => ({ page: {} as { props: Record<string, unknown> } }));

// The real dictionaries, not `fakeT`: a stub returning the English key would assert nothing about a
// dateline reading as a sentence in the site's language.
const dictionaries: Record<string, Record<string, string>> = { en, ja };
const translate = (key: string, replacements: Record<string, string | number> = {}): string =>
    Object.entries(replacements).reduce(
        (line, [name, value]) => line.replaceAll(`:${name}`, String(value)),
        dictionaries[String(inertia.page.props.locale)]?.[key] ?? key,
    );

vi.mock('@/lib/i18n', () => ({ useT: () => translate }));
vi.mock('@inertiajs/react', () => ({
    usePage: () => inertia.page,
    Link: ({ href, children, ...rest }: { href: string; children?: ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

beforeEach(() => {
    vi.useFakeTimers();
    // Noon on the 28th in Tokyo, so the 27th is yesterday.
    vi.setSystemTime(new Date('2026-08-28T03:00:00Z'));
});

afterEach(() => {
    cleanup();
    vi.useRealTimers();
});

/** The site's clock and language, which are what the band is rendered in. */
function inLocale(locale: string) {
    inertia.page = { props: { locale, timezone: 'Asia/Tokyo' } };
}

const day = (value: string) => ({ from: value, to: value });
const prev = { date: '2026-08-26', number: 11, href: '/home/2026/08/26' };
const next = { date: '2026-08-28', number: 13, href: '/home/2026/08/28' };

test('the calendar\'s yesterday is called that, with the day itself as the machine-readable value', () => {
    inLocale('ja');
    const { container } = renderWithProviders(<Masthead days={day('2026-08-27')} prev={prev} next={null} />);

    const time = container.querySelector('time');
    expect(time?.textContent).toBe('きのうのできごと');
    // The civil date itself — no instant, so no timezone can shift it.
    expect(time?.getAttribute('datetime')).toBe('2026-08-27');
});

test('before 06:00 the latest issue is two calendar days back, so it is named by its date', () => {
    inLocale('ja');
    vi.setSystemTime(new Date('2026-08-28T20:00:00Z'));
    renderWithProviders(<Masthead days={day('2026-08-27')} prev={prev} next={null} />);

    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('8月27日(木)のできごと');
});

test('an older day is named by its date and weekday, in the month the band has room for', () => {
    inLocale('ja');
    renderWithProviders(<Masthead days={day('2026-08-20')} prev={prev} next={next} />);
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('8月20日(木)のできごと');

    cleanup();

    inLocale('en');
    renderWithProviders(<Masthead days={day('2026-08-20')} prev={prev} next={next} />);
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('What happened on Thu, Aug 20');
});

test('a run of days names both ends, the second by what it does not share with the first', () => {
    inLocale('ja');
    const { container } = renderWithProviders(<Masthead days={{ from: '2026-08-21', to: '2026-08-27' }} prev={prev} next={null} />);

    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('8月21日〜27日のできごと');
    // HTML has no machine-readable range, and stamping the sentence with either end would name a day
    // it is not about.
    expect(container.querySelector('time')).toBeNull();

    cleanup();

    inLocale('en');
    renderWithProviders(<Masthead days={{ from: '2026-08-21', to: '2026-08-27' }} prev={prev} next={null} />);
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('What happened from Aug 21 to 27');
});

test('the band steps to the days either side, and says when the next one comes where there is none', () => {
    inLocale('ja');
    renderWithProviders(<Masthead days={day('2026-08-20')} prev={prev} next={next} />);

    expect(screen.getByRole('link', { name: '前の日' }).getAttribute('href')).toBe('/home/2026/08/26');
    expect(screen.getByRole('link', { name: '次の日' }).getAttribute('href')).toBe('/home/2026/08/28');
    expect(screen.queryByText('次の更新は朝6時')).toBeNull();

    cleanup();

    renderWithProviders(<Masthead days={day('2026-08-27')} prev={null} next={null} />);

    expect(screen.queryByRole('link')).toBeNull();
    expect(screen.getByText('次の更新は朝6時')).toBeTruthy();
});

test('a site with no issue yet names the page alone', () => {
    inLocale('ja');
    renderWithProviders(<Masthead days={null} prev={null} next={null} />);

    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('できごと');
});

test('a page left open past midnight stops calling its day yesterday', () => {
    inLocale('ja');
    // A minute before midnight in Tokyo on the 28th.
    vi.setSystemTime(new Date('2026-08-28T14:59:00Z'));
    renderWithProviders(<Masthead days={day('2026-08-27')} prev={prev} next={null} />);
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('きのうのできごと');

    act(() => {
        vi.advanceTimersByTime(61_000);
    });

    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('8月27日(木)のできごと');
});
