import react from '@vitejs/plugin-react';
import path from 'node:path';
import { defineConfig } from 'vitest/config';

/**
 * The Vite-transformed lane. `npm test` keeps two runners on purpose, split by extension:
 *
 * - `*.test.ts` runs under `node --test` — pure logic, no build step, no DOM.
 * - `*.test.tsx` runs here — whatever needs what node's type stripping does not do: JSX compiled,
 *   the `@/` alias resolved, `import.meta.glob` expanded. A rendered component is the common reason,
 *   not the only one.
 *
 * Scoped `include` keeps vitest off the node lane's files rather than running them twice.
 *
 * Standalone rather than merged with vite.config.ts: the app config's Laravel plugin wants a manifest
 * and an entry HTML that a test run has no use for.
 */
export default defineConfig({
    plugins: [react()],
    resolve: {
        alias: { '@': path.resolve(import.meta.dirname, 'resources/js') },
    },
    test: {
        include: ['resources/js/**/*.test.tsx'],
        environment: 'happy-dom',
    },
});
