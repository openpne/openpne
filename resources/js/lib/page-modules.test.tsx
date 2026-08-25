import { readdirSync } from 'node:fs';
import path from 'node:path';
import { expect, test } from 'vitest';
import { pageModules, pagePath } from './page-modules';

// In the vitest lane, not the node one: `import.meta.glob` needs Vite's transform.

const PAGES = path.join(import.meta.dirname, '../pages');

/** Every page file, as the key the glob is expected to give it. Walked, not globbed, so the check
 *  states the expectation independently of the pattern under test. */
function pagesOnDisk(dir = PAGES): string[] {
    return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) return pagesOnDisk(full);
        if (!entry.name.endsWith('.tsx') || entry.name.endsWith('.test.tsx')) return [];

        return [`../pages/${path.relative(PAGES, full)}`];
    });
}

test('resolves a page by name', () => {
    expect(pageModules[pagePath('timeline/index')]).toBeTypeOf('function');
});

// Equality, not "no test file in the map": a narrowed include ships a bundle where most pages 404,
// and nothing else in CI loads the built bundle, so that would reach production green.
test('holds every page on disk and nothing else', () => {
    const onDisk = pagesOnDisk();

    expect(onDisk.length).toBeGreaterThan(0);
    expect(Object.keys(pageModules).sort()).toEqual(onDisk.sort());
});
