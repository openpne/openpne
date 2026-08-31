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
// A `//` line between two JSX children is not a comment — it is a text node, and it is drawn on the
// screen. Nothing else catches it: it is valid JSX, so tsc and the build are happy, and the rule that
// would name it (`react/jsx-no-comment-textnodes`) belongs to a plugin this config does not carry.
// It is easy to reach for, because the same line *is* a comment a few characters earlier, inside the
// `{cond && (` that precedes the element. Shipped once (PR #681), visible on every visit.
const JSX_LINE_COMMENT_RESTRICTION = {
    // Anchored to the start of a line: a bare https:// inside a sentence is prose, and this rule
    // has no business stopping it. esquery takes no `m` flag, so the alternation stands in for one.
    selector: 'JSXText[value=/(^|\\n)\\s*\\/\\//]',
    message: 'This is a JSX text node, not a comment — it renders on screen. Use {/* … */}.',
};

// A glob over modules can put test files into the bundle: lib/page-modules.ts is the one that walks
// pages/, excludes them, and is checked against disk by its test — and that map is all the test
// checks. Rather than name the spellings that reach pages/ (`./**`, a `base` option, a bracket in
// the path), allow exactly the other glob the app has, the dictionaries, and refuse every other
// pattern: an array, a template, or any string that is not a /lang/*.json path. The page map carries
// an eslint-disable naming this rule; a new legitimate glob does the same, with its reason.
const GLOB_MESSAGE =
    'import.meta.glob here can ship test modules. The page map is lib/page-modules.ts; only the /lang/*.json dictionaries are globbed elsewhere.';
const GLOB_CALL = "CallExpression[callee.object.type='MetaProperty'][callee.property.name='glob']";
const GLOB_RESTRICTIONS = [
    { selector: `${GLOB_CALL}[arguments.0.type!='Literal']`, message: GLOB_MESSAGE },
    { selector: `${GLOB_CALL}[arguments.0.type='Literal'][arguments.0.value!=/^\\/lang\\/[^/]*\\.json$/]`, message: GLOB_MESSAGE },
];

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
        rules: {
            'no-restricted-syntax': ['error', ...DATE_FORMATTING_RESTRICTIONS, JSX_LINE_COMMENT_RESTRICTION, ...GLOB_RESTRICTIONS],
        },
    },
    {
        // date.ts is the one place Intl belongs; `ignores` takes a file out of the whole block, so the
        // other two restrictions have to be said again for it.
        files: ['resources/js/lib/date.ts'],
        rules: { 'no-restricted-syntax': ['error', JSX_LINE_COMMENT_RESTRICTION, ...GLOB_RESTRICTIONS] },
    },
    {
        // The raw formatters take an explicit locale + timezone, so a caller can pass the wrong pair.
        // Components reach them through <Timestamp> / <CivilDate>, which bind the site's; the hook is
        // for the values that are not a timestamp (a month label, the current year).
        files: ['resources/js/**/*.{ts,tsx}'],
        // The hooks that bind the formatters to the site, plus tests — which exist to exercise the raw
        // functions directly, so listing them one by one would just be churn.
        ignores: [
            'resources/js/lib/use-date-format.ts',
            'resources/js/lib/use-site-day.ts',
            'resources/js/lib/use-relative-refresh.ts',
            'resources/js/**/*.test.ts',
        ],
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
        // Test helpers are named `test-*` and pull in @testing-library. Production code importing one
        // puts that dependency in the shipped bundle, which is how a page test's 17 kB became 473 kB
        // of it — and unlike a stray page test, nothing about the module's path says it is test-only.
        files: ['resources/js/**/*.{ts,tsx}'],
        ignores: ['resources/js/**/*.test.{ts,tsx}'],
        rules: {
            'no-restricted-imports': [
                'error',
                {
                    patterns: [
                        {
                            regex: '(^@/|/|^\\./)test-[^/]*(\\.tsx?)?$',
                            message: 'A `test-*` module is for tests only — importing one ships @testing-library to visitors.',
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
                // Restated: this block replaces the general one's options rather than adding to them.
                ...DATE_FORMATTING_RESTRICTIONS,
                JSX_LINE_COMMENT_RESTRICTION,
                ...GLOB_RESTRICTIONS,
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
