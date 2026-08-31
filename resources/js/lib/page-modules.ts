import type { ResolvedComponent } from '@inertiajs/react';

/**
 * The Inertia page map, and the only place that spells how a page name becomes a key.
 *
 * The glob is what decides the production bundle's contents, so the exclusion is load-bearing rather
 * than tidiness: a matched `*.test.tsx` becomes a lazy chunk like any other page, putting
 * @testing-library and vitest into public/build for a chunk nothing ever requests.
 *
 * Lives here rather than in app.tsx so `page-modules.test.tsx` can assert on the resolved map — the
 * pattern and the thing that checks it stay one file apart, with no second copy of the pattern.
 *
 * Both patterns have to keep the same `../pages` base: the negation is resolved against the base the
 * affirmative one establishes, so narrowing the include alone silently stops excluding anything.
 */
// eslint-disable-next-line no-restricted-syntax -- the one glob over modules; every other is refused (eslint.config.js)
export const pageModules = import.meta.glob<ResolvedComponent>(['../pages/**/*.tsx', '!../pages/**/*.test.tsx']);

/** Keys are relative to this module, not to the caller, so no caller builds one itself. */
export const pagePath = (name: string) => `../pages/${name}.tsx`;
