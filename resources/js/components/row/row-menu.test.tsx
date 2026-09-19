import { cleanup, fireEvent, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { Pencil, Trash2 } from 'lucide-react';
import { afterEach, expect, test, vi } from 'vitest';
import { reactorsItem, RowMenu } from './row-menu';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...rest }: { href: string; children?: ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

const coarse = vi.hoisted(() => ({ value: false }));
vi.mock('@/lib/use-coarse-pointer', () => ({ useCoarsePointer: () => coarse.value }));

afterEach(() => {
    cleanup();
    coarse.value = false;
});

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));
const open = () => fireEvent.keyDown(screen.getByRole('button', { name: 'More actions' }), { key: 'Enter' });

test('a list with nothing in it draws no control', () => {
    renderWithProviders(<RowMenu items={[null, null]} />);

    expect(screen.queryByRole('button')).toBeNull();
});

test('the destructive choice stands last, past a divider, and a plain one runs its action once the menu has gone', async () => {
    const remove = vi.fn();
    // What the choice sees as focused is the contract: a dialog it opens records that as its way back.
    const edit = vi.fn(() => expect(document.activeElement).toBe(screen.getByRole('button', { name: 'More actions' })));
    renderWithProviders(<RowMenu items={[{ label: 'Delete', icon: Trash2, destructive: true, onSelect: remove }, { label: 'Edit', icon: Pencil, onSelect: edit }]} />);
    open();

    const items = screen.getAllByRole('menuitem');
    expect(items.map((item) => item.textContent)).toEqual(['Edit', 'Delete']);
    expect(screen.getByRole('separator')).toBeTruthy();

    fireEvent.click(items[0] as HTMLElement);
    expect(edit).not.toHaveBeenCalled();
    await tick();
    expect(screen.queryByRole('menu')).toBeNull();
    expect(edit).toHaveBeenCalled();
    expect(remove).not.toHaveBeenCalled();
});

test('a link item is a link, so it navigates the way the page does', () => {
    renderWithProviders(<RowMenu items={[{ label: 'Edit', icon: Pencil, href: '/diary/edit/7' }]} />);
    open();

    expect(screen.getByRole('menuitem', { name: 'Edit' }).getAttribute('href')).toBe('/diary/edit/7');
});

test('who reacted is listed for a reader who may see it, disabled while nobody has, and not at all for one who may not', () => {
    const show = vi.fn();
    const none = reactorsItem(fakeT, { chips: [], vocabulary: [], onShowReactors: show });
    const some = reactorsItem(fakeT, { chips: [{ emoji: '\u{1F44D}', count: 1, mine: false }], vocabulary: [], onShowReactors: show });

    expect(reactorsItem(fakeT, { chips: [], vocabulary: [] })).toBeNull();
    expect(none?.disabled).toBe(true);
    expect(some?.disabled).toBe(false);

    renderWithProviders(<RowMenu items={[none]} />);
    open();
    expect(screen.getByRole('menuitem', { name: 'See who reacted' }).getAttribute('aria-disabled')).toBe('true');
});

test('a finger gets the same choices in a sheet; a chosen one closes it before it runs, and focus comes back to the kebab either way', async () => {
    coarse.value = true;
    const remove = vi.fn(() => expect(document.activeElement).toBe(screen.getByRole('button', { name: 'More actions' })));
    renderWithProviders(<RowMenu items={[{ label: 'Edit', icon: Pencil, href: '/x' }, { label: 'Delete', icon: Trash2, destructive: true, onSelect: remove }]} />);

    fireEvent.click(screen.getByRole('button', { name: 'More actions' }));
    const dialog = screen.getByRole('dialog', { name: 'More actions' });
    expect(dialog).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Edit' }).getAttribute('href')).toBe('/x');

    fireEvent.click(screen.getByRole('button', { name: 'Delete' }));
    expect(screen.queryByRole('dialog')).toBeNull();
    await tick();
    expect(remove).toHaveBeenCalled();
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'More actions' }));

    fireEvent.click(screen.getByRole('button', { name: 'More actions' }));
    fireEvent.keyDown(screen.getByRole('dialog', { name: 'More actions' }), { key: 'Escape' });
    await tick();
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'More actions' }));
});
