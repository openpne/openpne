import js from '@eslint/js';
import reactHooks from 'eslint-plugin-react-hooks';
import reactRefresh from 'eslint-plugin-react-refresh';
import globals from 'globals';
import tseslint from 'typescript-eslint';

// typescript-eslint's parser imports the TypeScript compiler API, which TS 7.0 does not ship yet
// (the new API lands in 7.1). We run TypeScript's official side-by-side split: `tsc` stays TS 7 via
// `@typescript/native`, while the `typescript` package is aliased to `@typescript/typescript6` so
// tooling gets a TS 6 API. Drop the alias (package.json) once typescript-eslint supports the TS 7.1 API.
export default tseslint.config(
    { ignores: ['public/build'] },
    js.configs.recommended,
    tseslint.configs.recommended,
    {
        languageOptions: {
            globals: { ...globals.browser, ...globals.node },
        },
        plugins: { 'react-hooks': reactHooks },
        rules: {
            'react-hooks/rules-of-hooks': 'error',
            'react-hooks/exhaustive-deps': 'error',
        },
    },
    {
        // The Inertia entry must stay a pure side-effect module: a component defined here makes it a
        // Vite Fast Refresh boundary, and plugin-react's boundary self-import re-runs the top-level
        // createRoot in dev, mounting the app twice. Scope the refresh rule here to enforce that.
        files: ['resources/js/app.tsx'],
        plugins: { 'react-refresh': reactRefresh },
        rules: {
            'react-refresh/only-export-components': ['error', { allowConstantExport: true }],
        },
    },
    {
        // Global augmentation via declaration merging uses intentionally empty interfaces.
        files: ['resources/js/types/globals.d.ts'],
        rules: { '@typescript-eslint/no-empty-object-type': 'off' },
    },
);
