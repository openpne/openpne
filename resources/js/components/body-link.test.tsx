// @vitest-environment-options { "url": "https://sns.example.test/diary/9" }
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { BodyLink } from './body-link';
import { UserText } from './user-text';
import { fakeT } from '@/lib/test-i18n';

// useT reads the Inertia page for its term map, which a component test has no page to give it.
vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

const visit = vi.fn();
vi.mock('@inertiajs/react', () => ({ router: { visit: (...args: unknown[]) => visit(...args) } }));

beforeEach(() => visit.mockReset());
afterEach(cleanup);

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

test('the page the tests run on is this site', () => {
    expect(window.location.host).toBe('sns.example.test');
});

test('a link to another site opens a new tab and says so', () => {
    render(<BodyLink href="https://example.org/x">example.org</BodyLink>);
    const link = screen.getByRole('link');

    expect(link.getAttribute('target')).toBe('_blank');
    expect(link.getAttribute('rel')).toBe('noopener noreferrer nofollow');
    expect(link.textContent).toBe('example.org Opens in a new tab');
    expect(link.querySelector('.sr-only')?.textContent).toBe(' Opens in a new tab');
});

test('a link to this site is a plain anchor to its path that the router takes on a click', () => {
    render(<BodyLink href="http://sns.example.test/diary/1?page=2">a diary</BodyLink>);
    const link = screen.getByRole('link');

    expect(link.getAttribute('target')).toBeNull();
    expect(link.getAttribute('rel')).toBeNull();
    expect(link.getAttribute('href')).toBe('/diary/1?page=2');
    expect(link.textContent).toBe('a diary');

    expect(clickIsLeftToBrowser(link)).toBe(false);
    expect(visit).toHaveBeenCalledWith('/diary/1?page=2', expect.objectContaining({ onHttpException: expect.any(Function) }));
});

test('a modified click on a link to this site is left to the browser', () => {
    render(<BodyLink href="https://sns.example.test/diary/1">a diary</BodyLink>);

    expect(clickIsLeftToBrowser(screen.getByRole('link'), { ctrlKey: true })).toBe(true);
    expect(visit).not.toHaveBeenCalled();
});

test('a page of ours that answers without an Inertia page is loaded outright', () => {
    const assign = vi.fn();
    vi.stubGlobal('location', { ...window.location, host: 'sns.example.test', assign });
    render(<BodyLink href="https://sns.example.test/img/1.png">a file</BodyLink>);

    fireEvent.click(screen.getByRole('link'));
    const options = visit.mock.calls[0]?.[1] as { onHttpException: () => boolean };

    expect(options.onHttpException()).toBe(false);
    expect(assign).toHaveBeenCalledWith('/img/1.png');
    vi.unstubAllGlobals();
});

test('a plain body links each url by the same rule', () => {
    render(<UserText text="see https://sns.example.test/diary/1 and https://example.org/x" />);
    const links = screen.getAllByRole('link');

    expect(links.map((link) => link.getAttribute('href'))).toEqual(['/diary/1', 'https://example.org/x']);
    expect(links.map((link) => link.getAttribute('target'))).toEqual([null, '_blank']);
    expect(links.map((link) => link.textContent)).toEqual(['https://sns.example.test/diary/1', 'https://example.org/x Opens in a new tab']);
});
