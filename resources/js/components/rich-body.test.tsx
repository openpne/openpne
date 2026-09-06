// @vitest-environment-options { "url": "https://sns.example.test/diary/9" }
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { OwnLinksOpen } from './body-link';
import { RichBody } from './rich-body';

vi.mock('@/lib/i18n', () => ({ useT: () => (key: string) => key }));

const visit = vi.fn();
vi.mock('@inertiajs/react', () => ({ router: { visit: (...args: unknown[]) => visit(...args) } }));

beforeEach(() => visit.mockReset());
afterEach(cleanup);

// The shape App\Support\MarkdownText emits: a link to this site bare, one to another site hardened.
const html =
    '<p>see <a href="https://sns.example.test/diary/1">ours</a> and ' +
    '<a href="https://example.org/x" target="_blank" rel="noopener noreferrer nofollow">theirs<span class="sr-only"> Opens in a new tab</span></a></p>';

/** Whether the click was left to the browser, which is then stopped from actually navigating. */
function clickIsLeftToBrowser(element: Element, init?: MouseEventInit): boolean {
    let left = true;
    const stop = (event: Event) => {
        left = !event.defaultPrevented;
        event.preventDefault();
    };
    document.addEventListener('click', stop);
    fireEvent.click(element, init);
    document.removeEventListener('click', stop);

    return left;
}

test('a click on a link to this site inside server-rendered html goes to the router', () => {
    render(<RichBody body="" bodyHtml={html} />);

    expect(clickIsLeftToBrowser(screen.getByText('ours'))).toBe(false);
    expect(visit).toHaveBeenCalledWith('/diary/1', expect.objectContaining({ onHttpException: expect.any(Function) }));
});

test('a link the server sent to a new tab is left to the browser', () => {
    render(<RichBody body="" bodyHtml={html} />);

    expect(clickIsLeftToBrowser(screen.getByText('theirs'))).toBe(true);
    expect(visit).not.toHaveBeenCalled();
});

test('a click beside the links, or a modified click on one, is left to the browser', () => {
    render(<RichBody body="" bodyHtml={html} />);

    expect(clickIsLeftToBrowser(screen.getByText('see', { exact: false }))).toBe(true);
    expect(clickIsLeftToBrowser(screen.getByText('ours'), { metaKey: true })).toBe(true);
    expect(visit).not.toHaveBeenCalled();
});

test('where own links are set to open a new tab, a server-rendered link to this site is given the tab and the notice', () => {
    const { container, rerender } = render(
        <OwnLinksOpen.Provider value="new-tab">
            <RichBody body="" bodyHtml={html} />
        </OwnLinksOpen.Provider>,
    );
    const ours = screen.getByText('ours', { exact: false });

    expect(ours.getAttribute('target')).toBe('_blank');
    expect(ours.getAttribute('rel')).toBe('noopener');
    expect(ours.textContent).toBe('ours Opens in a new tab');
    // The link that already opened a new tab is not given a second notice.
    expect(container.querySelectorAll('.sr-only').length).toBe(2);
    expect(clickIsLeftToBrowser(ours)).toBe(true);
    expect(visit).not.toHaveBeenCalled();

    // A second pass over the same markup (the DOM is kept for an unchanged body) adds nothing.
    rerender(
        <OwnLinksOpen.Provider value="new-tab">
            <RichBody body="x" bodyHtml={html} />
        </OwnLinksOpen.Provider>,
    );
    expect(screen.getByText('ours', { exact: false })).toBe(ours);
    expect(container.querySelectorAll('.sr-only').length).toBe(2);

    // Back to opening in the app, the link is a bare anchor again and the router takes it.
    rerender(
        <OwnLinksOpen.Provider value="in-app">
            <RichBody body="x" bodyHtml={html} />
        </OwnLinksOpen.Provider>,
    );
    expect(ours.getAttribute('target')).toBeNull();
    expect(ours.textContent).toBe('ours');
    expect(container.querySelectorAll('.sr-only').length).toBe(1);
    expect(clickIsLeftToBrowser(ours)).toBe(false);
    expect(visit).toHaveBeenCalledWith('/diary/1', expect.anything());
});
