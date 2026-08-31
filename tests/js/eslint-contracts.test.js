import assert from 'node:assert/strict';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { ESLint } from 'eslint';

// The restrictions below are `no-restricted-syntax` options, which a later config block for the same
// files replaces wholesale rather than extends — so a green lint says nothing about whether they
// still apply. Lint the offending spellings through the real config and expect each refusal.

const eslint = new ESLint({ cwd: fileURLToPath(new URL('../../', import.meta.url)) });

async function messages(code, filePath) {
    const [result] = await eslint.lintText(code, { filePath });

    return result.messages.map((m) => m.message);
}

const REFUSED = 'A glob over modules here can ship test modules.';

test('the entry refuses every module glob, in every spelling, and lets the dictionaries through', async () => {
    const spellings = [
        "import.meta.glob('./pages/**/*.tsx');",
        "import.meta.glob(['../pages/**/*.tsx', '!../pages/**/*.test.tsx']);",
        'import.meta.glob(`./pages/**/*.tsx`);',
        "import.meta.glob('./**/*.tsx', { base: '../pages' });",
        "import.meta.glob('./**/*.tsx');",
        "import.meta.glob('./page[s]/**/*.tsx');",
        "import.meta.glob('./Pages/**/*.tsx');",
        "import.meta.glob(['/lang/*.json', './pages/**/*.tsx']);",
        // Vite's other glob: a dynamic import with a variable in its specifier.
        'const load = (n) => import(`./pages/${n}.tsx`);',
    ];

    for (const spelling of spellings) {
        const found = await messages(spelling, 'resources/js/app.tsx');
        assert.ok(found.some((m) => m.startsWith(REFUSED)), spelling);
    }

    const allowed = await messages(
        "import.meta.glob('/lang/*.json', { eager: true });\nconst one = () => import('./pages/timeline/index.tsx');",
        'resources/js/app.tsx',
    );
    assert.equal(allowed.filter((m) => m.startsWith(REFUSED)).length, 0);
});

test('a module outside the entry is refused the same glob, and the page map is not', async () => {
    for (const file of ['resources/js/lib/prefetch.ts', 'resources/js/lib/date.ts', 'resources/js/components/x.test.tsx']) {
        const found = await messages("export const m = import.meta.glob('../pages/**/*.tsx');", file);
        assert.ok(found.some((m) => m.startsWith(REFUSED)), file);
    }

    const pageMap = await messages(
        "// eslint-disable-next-line no-restricted-syntax -- the one glob over modules\nexport const m = import.meta.glob(['../pages/**/*.tsx', '!../pages/**/*.test.tsx']);",
        'resources/js/lib/page-modules.ts',
    );
    assert.deepEqual(pageMap, []);
});

test('the entry keeps every restriction its block restates', async () => {
    const found = await messages(
        'export const x = 1;\nclass Y {}\nnew Intl.DateTimeFormat();\nconst j = <b>\n// drawn on screen\n</b>;\n',
        'resources/js/app.tsx',
    );

    assert.ok(found.some((m) => m.includes('no exports')));
    assert.ok(found.some((m) => m.includes('no class definitions')));
    assert.ok(found.some((m) => m.startsWith('Format dates through')));
    assert.ok(found.some((m) => m.startsWith('This is a JSX text node')));
});
