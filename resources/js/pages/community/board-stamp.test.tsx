import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { BoardStamp, EventBoardStamp } from './board-stamp';
import { fakeT } from '@/lib/test-i18n';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));
vi.mock('@inertiajs/react', () => ({ usePage: () => ({ props: { locale: 'en', timezone: 'Asia/Tokyo' } }) }));

afterEach(cleanup);

const at = '2026-08-27T11:00:00+09:00';

test('the stamp says what it is: the last comment when there is one, the post otherwise', () => {
    render(<BoardStamp commentCount={2} bumpedAt={at} />);
    expect(screen.getByText(/Last comment:/)).toBeTruthy();

    cleanup();
    render(<BoardStamp commentCount={0} bumpedAt={at} />);
    expect(screen.getByText(/Posted:/)).toBeTruthy();
});

test("an event's stamp waits for the sm breakpoint and keeps its separator out of the accessible name", () => {
    const { container } = render(<EventBoardStamp commentCount={1} bumpedAt={at} />);
    const span = container.firstElementChild as HTMLElement;

    expect(span.className).toContain('hidden');
    expect(span.className).toContain('sm:inline');
    expect(container.querySelector('[aria-hidden]')?.textContent).toBe('·');
    expect(span.textContent).toContain('Last comment:');
});
