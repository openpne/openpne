import js from '@eslint/js';
import reactHooks from 'eslint-plugin-react-hooks';
import reactRefresh from 'eslint-plugin-react-refresh';
import globals from 'globals';
import tseslint from 'typescript-eslint';

// typescript-eslint's parser imports the TypeScript compiler API, which TS 7.0 does not ship yet
// (the new API lands in 7.1). We run TypeScript's official side-by-side split: `tsc` stays TS 7 via
// `@typescript/native`, while the `typescript` package is aliased to `@typescript/typescript6` so
// tooling gets a TS 6 API. Drop the alias (package.json) once typescript-eslint supports the TS 7.1 API.
// Date formatting belongs to resources/js/lib/date.ts, the one place the site's timezone and locale are
// applied — the browser's are wrong for both, which is how Modern and Classic came to disagree by the
// viewer's offset (docs/internals/runtime.md). Shared so the app.tsx block below, which sets its own
// no-restricted-syntax and would otherwise replace this one, keeps enforcing it too.
const DATE_FORMATTING_RESTRICTIONS = [
    {
        // The member access, not the call: `Intl.DateTimeFormat(...)` is valid without `new`, and
        // aliasing it to a variable would slip past a call-shaped selector too.
        selector: "MemberExpression[object.name='Intl'][property.name=/^(DateTimeFormat|RelativeTimeFormat)$/]",
        message: 'Format dates through resources/js/lib/date.ts (site timezone + locale), not Intl directly.',
    },
    {
        selector: 'CallExpression[callee.property.name=/^toLocale(String|DateString|TimeString)$/]',
        message: 'toLocale* formats in the browser timezone and locale. Use the helpers in resources/js/lib/date.ts.',
    },
];

export default tseslint.config(
    { ignores: ['public/build', 'public/js/filament'] },
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
        files: ['resources/js/**/*.{ts,tsx}'],
        ignores: ['resources/js/lib/date.ts'],
        rules: { 'no-restricted-syntax': ['error', ...DATE_FORMATTING_RESTRICTIONS] },
    },
    {
        // The raw formatters take an explicit locale + timezone, so a caller can pass the wrong pair.
        // Components reach them through <Timestamp> / <CivilDate>, which bind the site's; the hook is
        // for the values that are not a timestamp (a month label, the current year).
        files: ['resources/js/**/*.{ts,tsx}'],
        ignores: ['resources/js/lib/use-date-format.ts', 'resources/js/lib/date.test.ts'],
        rules: {
            'no-restricted-imports': [
                'error',
                {
                    // One regex over the specifier rather than `paths` or a glob list: `paths` matches
                    // only the literal string, and a glob list has to enumerate every spelling of the
                    // same module — the alias, each relative depth, and the `.ts` suffix that
                    // date.test.ts already uses and would be copied from.
                    patterns: [
                        {
                            regex: '(^@/lib/date|/lib/date|^\\./date)(\\.ts)?$',
                            message: 'Render timestamps with <Timestamp> / <CivilDate>; for other values use useDateFormat().',
                        },
                    ],
                },
            ],
        },
    },
    {
        // The Inertia entry must stay a pure side-effect module: a component defined here makes it a
        // Vite Fast Refresh boundary, and plugin-react's boundary self-import re-runs the top-level
        // createRoot in dev, mounting the app twice. Enforce the contract directly — no exports (a
        // module that exports nothing can never become a refresh boundary) and no class definitions;
        // only-export-components additionally flags a locally-defined function component.
        files: ['resources/js/app.tsx'],
        plugins: { 'react-refresh': reactRefresh },
        rules: {
            'react-refresh/only-export-components': 'error',
            'no-restricted-syntax': [
                'error',
                {
                    selector: 'ExportNamedDeclaration, ExportDefaultDeclaration, ExportAllDeclaration',
                    message:
                        'The Inertia entry is a side-effect-only module — no exports. Put components or helpers in their own module.',
                },
                {
                    selector: 'ClassDeclaration',
                    message:
                        'The Inertia entry is a side-effect-only module — no class definitions. Put them in their own module.',
                },
                ...DATE_FORMATTING_RESTRICTIONS,
            ],
        },
    },
    {
        // Global augmentation via declaration merging uses intentionally empty interfaces.
        files: ['resources/js/types/globals.d.ts'],
        rules: { '@typescript-eslint/no-empty-object-type': 'off' },
    },
    {
        // The Classic surface's scripts are served as-is from public/ — no bundler, no modules, and
        // no browser API newer than the ones OpenPNE 3's own audience is on.
        files: ['public/js/*.js'],
        languageOptions: {
            sourceType: 'script',
            globals: globals.browser,
        },
    },
    {
        // The hand-written service worker at the site root: a classic script (no modules), and it
        // needs the service-worker globals (self, clients, registration), not just browser ones.
        // public/js/filament is ignored above, but public/sw.js is deliberately linted.
        files: ['public/sw.js'],
        languageOptions: {
            sourceType: 'script',
            globals: globals.serviceworker,
        },
    },
);
