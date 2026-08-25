import { expect, test } from 'vitest';
import { pageModules, pagePath } from './page-modules';

// In the vitest lane, not the node one: `import.meta.glob` needs Vite's transform.

test('resolves a real page', () => {
    expect(pageModules[pagePath('timeline/index')]).toBeTypeOf('function');
});

test('excludes test modules from the page map', () => {
    // Without this the next assertion passes on an empty haystack — the way to fail is for the
    // include pattern to stop matching, not for the exclusion to start working.
    const testFiles = import.meta.glob('../pages/**/*.test.tsx');
    expect(Object.keys(testFiles).length).toBeGreaterThan(0);

    expect(Object.keys(pageModules).filter((key) => key.includes('.test.'))).toEqual([]);
});
