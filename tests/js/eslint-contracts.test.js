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
 * `pb-safe-4` and `text-2xs` in the refused snippet are the teeth of app.css: they exist only through
 * its utilities and tokens, so a theme that lost them would report them as unknown classes.
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

// An opaque style object is refused whole, so a dynamic value travels as a custom property in a
// literal object that a class reads; a raw color in a custom property is still refused.
test('a Modern module is refused an inline property, a colored custom property and an opaque style object, and keeps a dynamic custom property', async () => {
    const found = await messages(
        "const style = () => ({});\nexport const a = <div style={{ color: 'red', '--x': 'blue' }} />;\nexport const b = <div style={style()} />;\n",
        'resources/js/components/x.tsx',
    );

    assert.ok(found.some((m) => m.startsWith('Inline style sets color.')));
    assert.ok(found.some((m) => m.startsWith('Dynamic style object cannot be checked.')));
    assert.ok(found.some((m) => m.startsWith('Custom property --x hardcodes a color')));

    const allowed = await messages(
        "import type { CSSProperties } from 'react';\nexport function A({ hex }: { hex: string }) {\n    return <div className=\"bg-(--x)\" style={{ '--x': hex } as CSSProperties} />;\n}\n",
        'resources/js/components/x.tsx',
    );
    assert.deepEqual(allowed, []);
});

test('a Modern module is refused an appearance class on a styled ui component, and keeps layout, a slot, a body and a content container', async () => {
    const found = await messages(
        "import { Button } from '@/components/ui/button';\nexport const a = <Button className=\"mt-4 w-full bg-primary rounded-full\">x</Button>;\n",
        'resources/js/components/x.tsx',
    );

    assert.ok(found.some((m) => m.startsWith('"bg-primary" is not allowed on <Button>')));
    assert.ok(found.some((m) => m.startsWith('"rounded-full" is not allowed on <Button>')));
    assert.deepEqual(found.filter((m) => m.includes('"mt-4"') || m.includes('"w-full"')), []);

    const allowed = await messages(
        [
            "import { Button } from '@/components/ui/button';",
            "import { DialogTrigger } from '@/components/ui/dialog';",
            "import { Heading } from '@/components/ui/heading';",
            "import { PopoverContent } from '@/components/ui/popover';",
            "import { Panel } from '@/components/ui/surface';",
            'export const a = <DialogTrigger className="rounded-full rounded-field text-muted-foreground transition hover:bg-accent">x</DialogTrigger>;',
            'export const b = <Panel bodyClassName="space-y-4">x</Panel>;',
            'export const c = <Heading className="truncate line-clamp-2">x</Heading>;',
            'export const d = <PopoverContent className="flex gap-1">x</PopoverContent>;',
            'export const e = <Panel className="max-lg:mb-offset-8">x</Panel>;',
            'export const f = <Button className="mb-offset-4 top-safe-1">x</Button>;',
            '',
        ].join('\n'),
        'resources/js/components/x.tsx',
    );
    assert.deepEqual(allowed, []);
});

// The default instance reads no suppressions file, so this one points at a fixture: a file may keep
// as many findings as it had, and one more reports them all.
test('a suppressed file keeps its count of restyles and is reported whole when it grows', async () => {
    const suppressing = new ESLint({
        cwd: fileURLToPath(new URL('../../', import.meta.url)),
        applySuppressions: true,
        suppressionsLocation: 'tests/js/fixtures/eslint-suppressions.json',
    });
    const restyle = (n) => `import { Button } from '@/components/ui/button';\n${Array.from({ length: n }, (_, i) => `export const a${i} = <Button className="bg-primary">x</Button>;`).join('\n')}\n`;
    const lint = async (n) => (await suppressing.lintText(restyle(n), { filePath: 'resources/js/components/suppressed.tsx' }))[0].messages.filter((m) => m.ruleId === 'shadcn/no-restyle');

    assert.equal((await lint(1)).length, 0);
    assert.equal((await lint(2)).length, 2);
});
