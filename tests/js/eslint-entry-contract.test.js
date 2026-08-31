import assert from 'node:assert/strict';
import { test } from 'node:test';
import { ESLint } from 'eslint';

// The entry and page-glob rules are `no-restricted-syntax` options, which a later config block for
// the same files replaces wholesale rather than extends — so a green lint says nothing about whether
// they still apply. Lint the offending spellings through the real config and expect each refusal.

const eslint = new ESLint({ cwd: new URL('../../', import.meta.url).pathname });

async function messages(code, filePath) {
    const [result] = await eslint.lintText(code, { filePath });

    return result.messages.map((m) => m.message);
}

const REFUSED = 'import.meta.glob here can ship test modules.';

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
    ];

    for (const spelling of spellings) {
        const found = await messages(spelling, 'resources/js/app.tsx');
        assert.ok(found.some((m) => m.startsWith(REFUSED)), spelling);
    }

    const dictionaries = await messages("import.meta.glob('/lang/*.json', { eager: true });", 'resources/js/app.tsx');
    assert.equal(dictionaries.filter((m) => m.startsWith(REFUSED)).length, 0);
});

test('a module outside the entry is refused the same glob, and the page map is not', async () => {
    const found = await messages("export const m = import.meta.glob('../pages/**/*.tsx');", 'resources/js/lib/prefetch.ts');
    assert.ok(found.some((m) => m.startsWith(REFUSED)));

    const pageMap = await messages(
        "// eslint-disable-next-line no-restricted-syntax -- the one glob over modules\nexport const m = import.meta.glob(['../pages/**/*.tsx', '!../pages/**/*.test.tsx']);",
        'resources/js/lib/page-modules.ts',
    );
    assert.deepEqual(pageMap, []);
});

test('the entry refuses an export and a class', async () => {
    const found = await messages('export const x = 1;\nclass Y {}\n', 'resources/js/app.tsx');
    assert.ok(found.some((m) => m.includes('no exports')));
    assert.ok(found.some((m) => m.includes('no class definitions')));
});
