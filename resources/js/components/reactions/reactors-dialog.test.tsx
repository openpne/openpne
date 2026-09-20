import { act, cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { ReactorsDialog } from './reactors-dialog';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));
vi.mock('@inertiajs/react', () => ({ Link: ({ href, children }: { href: string; children?: React.ReactNode }) => <a href={href}>{children}</a> }));

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

const member = (id: number, name: string) => ({ id, name, imageUrl: null, avatarColor: null, isAi: false });

test('the group the chip was pressed on leads, and closing gives focus back to what opened it', async () => {
    vi.stubGlobal('fetch', () =>
        Promise.resolve(
            new Response(JSON.stringify({ groups: [{ emoji: '\u{1F44D}', count: 1, members: [member(1, 'Rin')] }, { emoji: '\u{2764}\u{FE0F}', count: 1, members: [member(2, 'Aoi')] }] }), { status: 200 }),
        ),
    );
    const opener = document.createElement('button');
    document.body.append(opener);
    opener.focus();
    const onClose = vi.fn();

    function Host({ open }: { open: boolean }) {
        return open ? <ReactorsDialog url="/x" emoji={'\u{2764}\u{FE0F}'} onClose={onClose} /> : null;
    }
    const { rerender } = renderWithProviders(<Host open />);

    const names = await screen.findAllByRole('link');
    expect(names.map((link) => link.textContent)).toEqual(['Aoi', 'Rin']);

    fireEvent.click(screen.getByRole('button', { name: 'Close' }));
    expect(onClose).toHaveBeenCalled();
    rerender(<Host open={false} />);
    await act(() => new Promise((resolve) => setTimeout(resolve, 0)));
    expect(document.activeElement).toBe(opener);
    opener.remove();
});
