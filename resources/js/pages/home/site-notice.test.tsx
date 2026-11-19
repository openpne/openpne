import { act, cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { SiteNotice } from './site-notice';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

let height = 0;
let resize: (() => void) | null = null;

beforeEach(() => {
    height = 0;
    resize = null;
    vi.spyOn(HTMLElement.prototype, 'scrollHeight', 'get').mockImplementation(() => height);
    vi.stubGlobal(
        'ResizeObserver',
        class {
            constructor(callback: () => void) {
                resize = callback;
            }
            observe() {}
            disconnect() {}
        },
    );
});

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

const notice = (text: string) => ({ body: text, bodyHtml: `<p>${text} <a href="/rules">rules</a></p>` });

test('nothing is drawn without a notice', () => {
    const { container } = renderWithProviders(<SiteNotice notice={null} />);

    expect(container.innerHTML).toBe('');
});

test('a short notice is shown whole, with no button', () => {
    height = 100;
    renderWithProviders(<SiteNotice notice={notice('Short')} />);

    expect(screen.getByRole('region', { name: 'Notice from the site' }).textContent).toContain('Short');
    expect(screen.queryByRole('button')).toBeNull();
});

test('the notice is named for assistive tech, with no heading on screen', () => {
    renderWithProviders(<SiteNotice notice={notice('Short')} />);

    expect(screen.getByRole('heading', { level: 2, name: 'Notice from the site' }).className).toContain('sr-only');
});

test('a long notice folds, and the button opens and closes it', () => {
    height = 600;
    renderWithProviders(<SiteNotice notice={notice('Long')} />);

    const button = screen.getByRole('button', { name: 'Read more' });
    const body = document.getElementById(button.getAttribute('aria-controls') ?? '');
    expect(button.getAttribute('aria-expanded')).toBe('false');
    expect(body?.className).toContain('max-h-[192px]');

    fireEvent.click(button);
    expect(button.getAttribute('aria-expanded')).toBe('true');
    expect(button.textContent).toBe('Show less');
    expect(body?.className).not.toContain('max-h-');

    fireEvent.click(button);
    expect(button.getAttribute('aria-expanded')).toBe('false');
});

test('reaching a link in the folded part opens the text', () => {
    height = 600;
    renderWithProviders(<SiteNotice notice={notice('Long')} />);

    fireEvent.focus(screen.getByRole('link', { name: 'rules' }));

    expect(screen.getByRole('button', { name: 'Show less' }).getAttribute('aria-expanded')).toBe('true');
});

test('the fold follows the measured height and a new text starts folded', () => {
    height = 100;
    const { rerender } = renderWithProviders(<SiteNotice notice={notice('First')} />);
    expect(screen.queryByRole('button')).toBeNull();

    height = 600;
    act(() => resize?.());
    fireEvent.click(screen.getByRole('button', { name: 'Read more' }));
    expect(screen.getByRole('button').getAttribute('aria-expanded')).toBe('true');

    rerender(<SiteNotice notice={notice('Second')} />);
    expect(screen.getByRole('button', { name: 'Read more' }).getAttribute('aria-expanded')).toBe('false');
});
