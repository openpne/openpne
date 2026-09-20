import { act, cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { DetailReactionChips, RowReactionChips, reactorNames } from './reaction-bar';
import { fakeT } from '@/lib/test-i18n';
import { stubCoarsePointer } from '@/lib/test-pointer';
import { renderWithProviders } from '@/lib/test-render';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

afterEach(() => {
    cleanup();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

const chips = [{ emoji: '\u{1F44D}', count: 2, mine: false }];
const vocabulary = ['\u{1F44D}'];
const url = '/timeline/7/reactions';

test('a list row with chips gives a reader who may react the toggles and the add button, and no reactor button', () => {
    renderWithProviders(<RowReactionChips reactions={{ chips, vocabulary, onToggle: vi.fn(), onShowReactors: vi.fn(), reactorsUrl: url }} />);

    expect(screen.getByRole('button', { name: /2/ })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Add a reaction' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'See who reacted' })).toBeNull();
});

test('a list row with no chips draws nothing, whoever reads it', () => {
    const { container } = renderWithProviders(<RowReactionChips reactions={{ chips: [], vocabulary, onToggle: vi.fn(), onShowReactors: vi.fn(), reactorsUrl: url }} />);

    expect(container.querySelector('[data-reactions]')).toBeNull();
});

test('a detail item keeps its add button with no chips at all', () => {
    renderWithProviders(<DetailReactionChips reactions={{ chips: [], vocabulary, onToggle: vi.fn(), onShowReactors: vi.fn(), reactorsUrl: url }} />);

    expect(screen.getByRole('button', { name: 'Add a reaction' })).toBeTruthy();
});

test('a reader who may not react gets counts and nothing to press', () => {
    renderWithProviders(<RowReactionChips reactions={{ chips, vocabulary }} />);

    expect(screen.getByText('2')).toBeTruthy();
    expect(screen.queryAllByRole('button')).toHaveLength(0);
});

test('a finger held on a chip asks for that emoji\'s reactors; a tap is the toggle alone', () => {
    vi.useFakeTimers();
    stubCoarsePointer();
    const onToggle = vi.fn();
    const onShowReactors = vi.fn();
    renderWithProviders(<RowReactionChips reactions={{ chips, vocabulary, onToggle, onShowReactors, reactorsUrl: url }} />);
    const chip = screen.getByRole('button', { name: /2/ });

    fireEvent.pointerDown(chip, { pointerType: 'touch', isPrimary: true, clientX: 10, clientY: 10 });
    fireEvent.pointerUp(chip);
    fireEvent.click(chip);
    expect(onToggle).toHaveBeenCalledWith('\u{1F44D}', false);
    expect(onShowReactors).not.toHaveBeenCalled();

    fireEvent.pointerDown(chip, { pointerType: 'touch', isPrimary: true, clientX: 10, clientY: 10 });
    act(() => {
        vi.advanceTimersByTime(600);
    });
    expect(onShowReactors).toHaveBeenCalledWith('\u{1F44D}');
});

const settle = (ms: number) => act(() => new Promise((resolve) => setTimeout(resolve, ms)));

test('a chip reached by keyboard names its reactors in a tip once it has stayed open; the chip unchanged, a later open reads nothing again; its count moved, it reads anew', async () => {
    const fetch = vi.fn(() =>
        Promise.resolve(new Response(JSON.stringify({ groups: [{ emoji: '\u{1F44D}', count: 3, members: [{ id: 1, name: 'Rin', imageUrl: null, avatarColor: null, isAi: false }, { id: 2, name: 'Aoi', imageUrl: null, avatarColor: null, isAi: false }] }] }), { status: 200 })),
    );
    vi.stubGlobal('fetch', fetch);
    const reactions = { chips, vocabulary, onToggle: vi.fn(), onShowReactors: vi.fn(), reactorsUrl: url };
    const { rerender } = renderWithProviders(<RowReactionChips reactions={reactions} />);
    const chip = screen.getByRole('button', { name: /2/ });

    fireEvent.focus(chip);
    expect(fetch).not.toHaveBeenCalled();
    expect((await screen.findAllByText('Rin, Aoi and 1 more')).length).toBeGreaterThan(0);
    expect(document.getElementById(chip.getAttribute('aria-describedby')!)?.className).not.toContain('invisible');
    expect(fetch).toHaveBeenCalledWith(url, expect.objectContaining({ credentials: 'same-origin' }));

    fireEvent.blur(chip);
    fireEvent.focus(chip);
    await settle(500);
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(document.getElementById(chip.getAttribute('aria-describedby')!)?.textContent).toBe('Rin, Aoi and 1 more');

    fireEvent.blur(chip);
    rerender(<RowReactionChips reactions={{ ...reactions, chips: [{ emoji: '\u{1F44D}', count: 2, mine: true }] }} />);
    fireEvent.focus(screen.getByRole('button', { name: /2/ }));
    await settle(500);
    expect(fetch).toHaveBeenCalledTimes(2);

    fireEvent.blur(chip);
    rerender(<RowReactionChips reactions={{ ...reactions, chips: [{ emoji: '\u{1F44D}', count: 3, mine: true }] }} />);
    fireEvent.focus(screen.getByRole('button', { name: /3/ }));
    await settle(500);
    expect(fetch).toHaveBeenCalledTimes(3);
});

test('names that landed after the count moved are not kept for the moved chip', async () => {
    let answer: ((response: Response) => void) | undefined;
    const fetch = vi.fn(() => new Promise<Response>((resolve) => { answer = resolve; }));
    vi.stubGlobal('fetch', fetch);
    const reactions = { chips, vocabulary, onToggle: vi.fn(), onShowReactors: vi.fn(), reactorsUrl: url };
    const { rerender } = renderWithProviders(<RowReactionChips reactions={reactions} />);

    fireEvent.focus(screen.getByRole('button', { name: /2/ }));
    await settle(400);
    expect(fetch).toHaveBeenCalledTimes(1);
    rerender(<RowReactionChips reactions={{ ...reactions, chips: [{ emoji: '\u{1F44D}', count: 3, mine: false }] }} />);
    answer?.(new Response(JSON.stringify({ groups: [{ emoji: '\u{1F44D}', count: 2, members: [{ id: 1, name: 'Rin', imageUrl: null, avatarColor: null, isAi: false }, { id: 2, name: 'Aoi', imageUrl: null, avatarColor: null, isAi: false }] }] }), { status: 200 }));
    await settle(0);

    const chip = screen.getByRole('button', { name: /3/ });
    fireEvent.blur(chip);
    fireEvent.focus(chip);
    await settle(400);
    expect(fetch).toHaveBeenCalledTimes(2);
});

test('a read that brought no names is tried again on the next open', async () => {
    const answers = [Promise.resolve(new Response('', { status: 429 })), Promise.resolve(new Response(JSON.stringify({ groups: [{ emoji: '\u{1F44D}', count: 1, members: [{ id: 1, name: 'Rin', imageUrl: null, avatarColor: null, isAi: false }] }] }), { status: 200 }))];
    const fetch = vi.fn(() => answers.shift() ?? new Promise<Response>(() => {}));
    vi.stubGlobal('fetch', fetch);
    renderWithProviders(<RowReactionChips reactions={{ chips, vocabulary, onToggle: vi.fn(), onShowReactors: vi.fn(), reactorsUrl: url }} />);
    const chip = screen.getByRole('button', { name: /2/ });

    fireEvent.focus(chip);
    await waitFor(() => expect(document.getElementById(chip.getAttribute('aria-describedby') ?? '')?.textContent).toBe(''));
    fireEvent.blur(chip);
    fireEvent.focus(chip);
    expect((await screen.findAllByText('Rin')).length).toBeGreaterThan(0);
    expect(fetch).toHaveBeenCalledTimes(2);
});

test('a tip closed before it has stayed open a moment reads nothing', async () => {
    const fetch = vi.fn(() => new Promise<Response>(() => {}));
    vi.stubGlobal('fetch', fetch);
    renderWithProviders(<RowReactionChips reactions={{ chips, vocabulary, onToggle: vi.fn(), onShowReactors: vi.fn(), reactorsUrl: url }} />);
    const chip = screen.getByRole('button', { name: /2/ });

    fireEvent.focus(chip);
    await settle(50);
    fireEvent.blur(chip);
    await settle(400);
    expect(fetch).not.toHaveBeenCalled();
});

test('the tip describes the chip from the moment it opens, before the names arrive, and is unseen until they do', async () => {
    vi.stubGlobal('fetch', () => new Promise<Response>(() => {}));
    renderWithProviders(<RowReactionChips reactions={{ chips, vocabulary, onToggle: vi.fn(), onShowReactors: vi.fn(), reactorsUrl: url }} />);
    const chip = screen.getByRole('button', { name: /2/ });

    fireEvent.focus(chip);
    const described = await waitFor(() => {
        const id = chip.getAttribute('aria-describedby');
        expect(id).toBeTruthy();

        return document.getElementById(id!);
    });
    expect(described?.textContent).toBe('Loading…');
    expect(described?.className).toContain('invisible');
});

async function undescribedAfterFocus(fetch: () => Promise<Response>) {
    vi.stubGlobal('fetch', fetch);
    renderWithProviders(<RowReactionChips reactions={{ chips, vocabulary, onToggle: vi.fn(), onShowReactors: vi.fn(), reactorsUrl: url }} />);
    const chip = screen.getByRole('button', { name: /2/ });

    fireEvent.focus(chip);
    await waitFor(() => {
        const described = document.getElementById(chip.getAttribute('aria-describedby') ?? '');
        expect(described?.textContent).toBe('');
        expect(described?.className).toContain('invisible');
    });
}

test('a read the route refuses leaves the tip unseen and the chip undescribed', async () => {
    await undescribedAfterFocus(() => Promise.resolve(new Response('', { status: 404 })));
});

test('a read that fails leaves the tip unseen and the chip undescribed', async () => {
    await undescribedAfterFocus(() => Promise.reject(new Error('offline')));
});

test('a read answered with a login page leaves the tip unseen and the chip undescribed', async () => {
    await undescribedAfterFocus(() => Promise.resolve(new Response('<!doctype html>', { status: 200 })));
});

test('a read whose group carries no member list leaves the tip unseen and the chip undescribed', async () => {
    await undescribedAfterFocus(() => Promise.resolve(new Response(JSON.stringify({ groups: [{ emoji: '\u{1F44D}', count: 2, members: null }] }), { status: 200 })));
});

test('a slow answer to an earlier open cannot land on a later one', async () => {
    let answerFirst: ((response: Response) => void) | undefined;
    const answers = [new Promise<Response>((resolve) => { answerFirst = resolve; }), Promise.resolve(new Response(JSON.stringify({ groups: [{ emoji: '\u{1F44D}', count: 1, members: [{ id: 2, name: 'Aoi', imageUrl: null, avatarColor: null, isAi: false }] }] }), { status: 200 }))];
    vi.stubGlobal('fetch', () => answers.shift());
    renderWithProviders(<RowReactionChips reactions={{ chips, vocabulary, onToggle: vi.fn(), onShowReactors: vi.fn(), reactorsUrl: url }} />);
    const chip = screen.getByRole('button', { name: /2/ });

    fireEvent.focus(chip);
    await settle(400);
    fireEvent.blur(chip);
    fireEvent.focus(chip);
    expect((await screen.findAllByText('Aoi')).length).toBeGreaterThan(0);

    answerFirst?.(new Response(JSON.stringify({ groups: [{ emoji: '\u{1F44D}', count: 1, members: [{ id: 1, name: 'Rin', imageUrl: null, avatarColor: null, isAi: false }] }] }), { status: 200 }));
    await act(() => new Promise((resolve) => setTimeout(resolve, 0)));
    expect(screen.queryByText('Rin')).toBeNull();
    expect(screen.getAllByText('Aoi').length).toBeGreaterThan(0);
});

test('a read that finds the reaction gone leaves the tip unseen and the chip undescribed', async () => {
    await undescribedAfterFocus(() => Promise.resolve(new Response(JSON.stringify({ groups: [] }), { status: 200 })));
});

test('a tip lists twenty names and counts the rest, whatever the server sent', () => {
    const members = Array.from({ length: 25 }, (_, i) => ({ id: i + 1, name: `m${i + 1}`, imageUrl: null, avatarColor: null, isAi: false }));

    expect(reactorNames({ emoji: '\u{1F44D}', count: 60, members }, fakeT)).toBe(`${members.slice(0, 20).map((m) => m.name).join(', ')} and 40 more`);
    expect(reactorNames({ emoji: '\u{1F44D}', count: 2, members: members.slice(0, 2) }, fakeT)).toBe('m1, m2');
    expect(reactorNames({ emoji: '\u{1F44D}', count: 3, members: [] }, fakeT)).toBe('');
});

test('a finger on a chip opens no tip and reads nothing (Radix leaves a pointerdown-born focus closed; this pins that upstream behaviour)', () => {
    vi.useFakeTimers();
    stubCoarsePointer();
    const fetch = vi.fn(() => new Promise<Response>(() => {}));
    vi.stubGlobal('fetch', fetch);
    renderWithProviders(<RowReactionChips reactions={{ chips, vocabulary, onToggle: vi.fn(), onShowReactors: vi.fn(), reactorsUrl: url }} />);
    const chip = screen.getByRole('button', { name: /2/ });

    fireEvent.pointerDown(chip, { pointerType: 'touch', isPrimary: true, clientX: 10, clientY: 10 });
    fireEvent.focus(chip);
    act(() => {
        vi.advanceTimersByTime(600);
    });

    expect(fetch).not.toHaveBeenCalled();
    expect(chip.getAttribute('aria-describedby')).toBeNull();
});
