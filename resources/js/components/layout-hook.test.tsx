import { cleanup, render } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { LayoutHook } from './layout-hook';

const inertia = vi.hoisted(() => ({ page: { props: {} as Record<string, unknown> } }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => inertia.page,
}));

afterEach(cleanup);

function arrive(layoutHooks?: Record<string, string>) {
    inertia.page = { props: layoutHooks === undefined ? {} : { layoutHooks } };
}

test('draws nothing without an entry, and nothing when the page carries no map at all', () => {
    arrive({ 'modern.main_end': '<b>there</b>' });
    const { container, rerender } = render(<LayoutHook name="modern.main_start" />);
    expect(container.innerHTML).toBe('');

    arrive();
    rerender(<LayoutHook name="modern.main_start" />);
    expect(container.innerHTML).toBe('');
});

test('writes the HTML into a marked wrapper, an aside where it stands outside every landmark', () => {
    arrive({ 'modern.auth_end': '<b>sponsor</b>' });
    const { container } = render(<LayoutHook name="modern.auth_end" landmark />);

    const wrapper = container.firstElementChild;
    expect(wrapper?.tagName).toBe('ASIDE');
    expect(wrapper?.getAttribute('data-layout-hook')).toBe('modern.auth_end');
    expect(wrapper?.innerHTML).toBe('<b>sponsor</b>');
});

test('a script arrives as a fresh element, which is what makes it run', () => {
    arrive({ 'modern.main_end': '<script data-ad="1">window.__probe = 1</script><span>x</span>' });
    const { container } = render(<LayoutHook name="modern.main_end" />);

    const script = container.querySelector('script');
    expect(script?.getAttribute('data-ad')).toBe('1');
    expect(script?.textContent).toBe('window.__probe = 1');
    expect(script?.nextElementSibling?.tagName).toBe('SPAN');
    // innerHTML parsed the script into one node; the one standing here was created afterwards.
    expect(script).not.toBe(new DOMParser().parseFromString(container.innerHTML, 'text/html').querySelector('script'));
});

test('the same string leaves the DOM alone; a new one rewrites it; unmount empties it', () => {
    arrive({ 'modern.main_end': '<b>one</b>' });
    const { container, rerender, unmount } = render(<LayoutHook name="modern.main_end" />);
    const first = container.querySelector('b');

    rerender(<LayoutHook name="modern.main_end" />);
    expect(container.querySelector('b')).toBe(first);

    arrive({ 'modern.main_end': '<b>two</b>' });
    rerender(<LayoutHook name="modern.main_end" />);
    expect(container.querySelector('b')?.textContent).toBe('two');

    const wrapper = container.firstElementChild;
    unmount();
    expect(wrapper?.innerHTML).toBe('');
});
