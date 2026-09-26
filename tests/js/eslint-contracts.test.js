import assert from 'node:assert/strict';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { ESLint } from 'eslint';

// `no-restricted-syntax` options are replaced wholesale by a later config block for the same files,
// so a green lint says nothing about whether these still apply.

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
        // The allowlist is the /lang/*.json path, not the extension.
        "import.meta.glob('./x/y.json');",
        "import.meta.glob('/lang/ja/*.json');",
    ];

    for (const spelling of spellings) {
        const found = await messages(spelling, 'resources/js/app.tsx');
        assert.ok(found.some((m) => m.startsWith(REFUSED)), spelling);
    }

    // A dynamic import Vite may turn into a glob: an expression in the specifier.
    for (const spelling of ['const load = (n) => import(`./pages/${n}.tsx`);', 'const load = (p) => import(p);']) {
        const found = await messages(spelling, 'resources/js/app.tsx');
        assert.ok(found.some((m) => m.startsWith('A dynamic import with an expression')), spelling);
    }

    const allowed = await messages(
        "import.meta.glob('/lang/*.json', { eager: true });\nconst one = () => import('./pages/timeline/index.tsx');\nconst two = () => import(`./pages/timeline/index.tsx`);",
        'resources/js/app.tsx',
    );
    assert.deepEqual(allowed.filter((m) => m.startsWith(REFUSED) || m.startsWith('A dynamic import')), []);
});

test('a module outside the entry keeps the JSX text-node and date rules', async () => {
    const found = await messages('new Intl.DateTimeFormat();\nconst j = <b>\n// drawn on screen\n</b>;\n', 'resources/js/components/x.tsx');

    assert.ok(found.some((m) => m.startsWith('Format dates through')));
    assert.ok(found.some((m) => m.startsWith('This is a JSX text node')));
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

/**
 * The misspelled token is the teeth of the theme: with no declared colors the rule lets an undeclared
 * name through. `rounded-field` exists only through the theme's `--radius-field`.
 */
test('a Modern module is refused a palette color, an undeclared token, a class Tailwind cannot generate, and a class it cannot read', async () => {
    const found = await messages(
        "import { Button } from '@/components/ui/button';\nimport { headingVariants } from '@/components/ui/heading';\nexport const a = <div className=\"bg-pink-500 bg-primry rounded-huge\" />;\nexport const b = <Button className={headingVariants({ variant: 'section' })}>x</Button>;\n",
        'resources/js/components/x.tsx',
    );

    assert.ok(found.some((m) => m.startsWith('"bg-pink-500" uses the raw Tailwind palette')));
    assert.ok(found.some((m) => m.startsWith('"bg-primry" is not a declared theme color. Did you mean "bg-primary"?')));
    assert.ok(found.some((m) => m.startsWith('"rounded-huge" is not a class this project\'s Tailwind knows')));
    assert.ok(found.some((m) => m.startsWith('Dynamically built className on <Button>')));

    const allowed = await messages(
        "import { Button } from '@/components/ui/button';\nexport const a = <div className=\"bg-primary rounded-field\" />;\nexport const b = <Button className=\"w-full\">x</Button>;\n",
        'resources/js/components/x.tsx',
    );
    assert.deepEqual(allowed, []);
});

/**
 * The allowed snippet is the teeth of app.css: `pb-safe-4` and `text-2xs` exist only through its
 * utilities and tokens, so a theme that lost them would be refused as unknown classes.
 */
test('a Modern module is refused an off-scale value and an arbitrary transition outside the named two', async () => {
    const found = await messages('export const a = <div className="p-[13px] transition-[height] pb-safe-4 text-2xs" />;\n', 'resources/js/components/x.tsx');

    assert.ok(found.some((m) => m.startsWith('"p-[13px]" hardcodes an off-token value. Use "p-3.25" instead')));
    assert.ok(found.some((m) => m.startsWith('"transition-[height]" hardcodes an off-token value')));
    assert.deepEqual(
        found.filter((m) => m.includes('pb-safe-4') || m.includes('text-2xs')),
        [],
    );

    const allowed = await messages('export const a = <div className="w-[calc(100vw-2rem)] transition-[padding-bottom] pb-(--modern-bottom-offset)" />;\n', 'resources/js/components/x.tsx');
    assert.deepEqual(allowed, []);
});
