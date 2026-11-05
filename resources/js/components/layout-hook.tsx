import { usePage } from '@inertiajs/react';
import { createElement, useEffect, useRef } from 'react';
import type { PageProps } from '@/types';

/**
 * The HTML a package registered for one shell position (docs/internals/layout-hooks.md, "Modern").
 * Written through innerHTML and the scripts re-created, because a script that arrives by innerHTML never runs.
 */
export function LayoutHook({ name, landmark = false }: { name: string; landmark?: boolean }) {
    const html = usePage<PageProps>().props.layoutHooks?.[name];
    const root = useRef<HTMLElement>(null);

    useEffect(() => {
        const node = root.current;
        if (node === null || html === undefined) {
            return;
        }
        node.innerHTML = html;
        for (const stale of node.querySelectorAll('script')) {
            const script = document.createElement('script');
            for (const { name: attribute, value } of stale.attributes) {
                script.setAttribute(attribute, value);
            }
            script.textContent = stale.textContent;
            stale.replaceWith(script);
        }

        return () => {
            node.replaceChildren();
        };
    }, [html]);

    if (html === undefined) {
        return null;
    }

    return createElement(landmark ? 'aside' : 'div', { ref: root, 'data-layout-hook': name });
}
