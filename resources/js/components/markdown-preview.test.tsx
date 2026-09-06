// @vitest-environment-options { "url": "https://sns.example.test/diary/new" }
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { MarkdownPreview } from './markdown-preview';

vi.mock('@/lib/i18n', () => ({ useT: () => (key: string) => key }));

const visit = vi.fn();
vi.mock('@inertiajs/react', () => ({ router: { visit: (...args: unknown[]) => visit(...args) } }));

beforeEach(() => visit.mockReset());
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

test('a link to this site inside the preview opens a new tab and says so, keeping the draft', async () => {
    // The shape the preview endpoint returns for a link to this site: a bare anchor.
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => ({ ok: true, json: async () => ({ html: '<p><a href="https://sns.example.test/diary/1">a diary</a></p>' }) })),
    );
    render(<MarkdownPreview body="[a diary](https://sns.example.test/diary/1)" enabled />);

    // The preview debounces its request by half a second.
    const link = await waitFor(() => screen.getByText('a diary', { exact: false }), { timeout: 3000 });

    expect(link.getAttribute('target')).toBe('_blank');
    expect(link.textContent).toBe('a diary Opens in a new tab');

    let left = true;
    const stop = (event: Event) => {
        left = !event.defaultPrevented;
        event.preventDefault();
    };
    document.addEventListener('click', stop);
    fireEvent.click(link);
    document.removeEventListener('click', stop);

    expect(left).toBe(true);
    expect(visit).not.toHaveBeenCalled();
});
