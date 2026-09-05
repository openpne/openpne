import type { ResolvedComponent } from '@inertiajs/react';

/**
 * The glob decides the production bundle's contents, so excluding `*.test.tsx` is load-bearing: a
 * matched test file becomes a lazy chunk carrying @testing-library and vitest into public/build.
 * Both patterns have to keep the same `../pages` base, because the negation is resolved against the
 * base the affirmative one establishes.
 */
// eslint-disable-next-line no-restricted-syntax -- the one glob over modules; every other is refused (eslint.config.js)
export const pageModules = import.meta.glob<ResolvedComponent>(['../pages/**/*.tsx', '!../pages/**/*.test.tsx']);

/** Keys are relative to this module, not to the caller, so no caller builds one itself. */
export const pagePath = (name: string) => `../pages/${name}.tsx`;
