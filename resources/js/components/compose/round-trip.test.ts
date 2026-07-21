// Register the DOM before anything that builds a ProseMirror view.
import './test-dom.ts';

import assert from 'node:assert/strict';
import { test } from 'node:test';
import { Editor } from '@tiptap/core';
import type { JSONContent } from '@tiptap/core';
import { createComposeEditorOptions, parseMarkdown, serializeMarkdown } from './editor-extensions.ts';

function makeEditor(): Editor {
    return new Editor({
        element: document.createElement('div'),
        ...createComposeEditorOptions({ initialMarkdown: '', onChange: () => {} }),
    });
}

const editor = makeEditor();

/**
 * Parse Markdown and serialize it back through the production config. Trailing block separators the
 * serializer appends carry no meaning, so trim the ends; no fixture here depends on edge whitespace.
 */
function roundTrip(md: string): string {
    parseMarkdown(editor, md);
    return serializeMarkdown(editor).trim();
}

/** True when the string contains a raw HTML tag opener (`<` then an ASCII letter). */
function hasRawHtmlTag(md: string): boolean {
    return /<[A-Za-z]/.test(md);
}

/** The parsed document's plain text — used to assert what a construct becomes in the editor. */
function docText(md: string): string {
    parseMarkdown(editor, md);
    return editor.getText().trim();
}

/** The joined text of each cell in the first table row of the parsed document. */
function firstTableRowCells(md: string): string[] {
    parseMarkdown(editor, md);
    const doc: JSONContent = editor.getJSON();
    const table = doc.content?.find((node) => node.type === 'table');
    const row = table?.content?.[0];
    return (row?.content ?? []).map((cell: JSONContent) =>
        ((cell.content?.[0]?.content ?? []) as JSONContent[]).map((t) => t.text ?? '').join(''),
    );
}

// ─── tier 1: exact preservation ────────────────────────────────────────────────────────────────

for (const md of [
    '**bold**',
    '*italic*',
    '~~strike~~',
    '`code`',
    '# h1',
    '## h2',
    '### h3',
    '#### h4',
    '##### h5',
    '###### h6',
    '```\ncode block\n```',
    '```js\nconst x = 1\n```',
    '[text](https://example.com)',
    '- a\n- b',
    '- a\n  - b\n    - c',
    '1. one\n2. two',
    '3. three\n4. four',
    '> quote',
    '---',
    // A task-like line inside a code block is not a list item: neutralization leaves it untouched.
    '```\n- [ ] in code\n```',
]) {
    test(`tier-1 exact: ${JSON.stringify(md)}`, () => {
        assert.equal(roundTrip(md), md);
    });
}

// ─── tier 2: idempotence (roundTrip is stable on a second pass) ──────────────────────────────────

for (const md of [
    '- a\n\n- b', // loose list → tight
    'Title\n=====', // setext h1 → ATX
    'Sub\n-----', // setext h2 → ATX
    '***', // asterisk hr → ---
    '| a | b |\n|:--|--:|\n| 1 | 2 |', // table with alignment colons
    '| a \\| b | c |\n| --- | --- |\n| 1 | 2 |', // escaped pipe in a cell
    '> - one\n> - two', // blockquote containing a list
    'a\r\nb\r\nc', // CRLF input
    '- [ ] task', // GFM task marker (unchecked)
    '- [x] done', // GFM task marker (checked)
    '- [ ] a\n- [x] b', // mixed task markers
    '- [ ] outer\n  - [x] inner', // nested task markers
    '![a\\]b](https://example.com/a.png "cap")', // image: escaped ] in alt + title
    '![<b>x](https://e.com/y.png)', // image: <tag> in alt
    '![k](https://e.com/x.png "t<i>")', // image: <tag> in title
    '![a *b* c](https://e.com/z.png)', // image: emphasis chars in alt
]) {
    test(`tier-2 idempotent: ${JSON.stringify(md)}`, () => {
        const once = roundTrip(md);
        assert.equal(roundTrip(once), once);
    });
}

// ─── tier 3: behavior pinning (assert the actual, not the ideal) ─────────────────────────────────

test('tier-3: bare-URL autolink normalizes to an explicit link (server renders both identically)', () => {
    // Demoted from tier-1: the round-trip is a semantically-equal but different form.
    assert.equal(roundTrip('https://example.com'), '[https://example.com](https://example.com)');
});

test('tier-3: hard break serializes as two trailing spaces (server soft_break = <br>)', () => {
    // Demoted from tier-1: `\n` → `  \n`; both render as <br> on the server.
    assert.equal(roundTrip('line1\nline2'), 'line1  \nline2');
});

test('tier-3: simple GFM table normalizes cell padding (demoted from tier-1)', () => {
    assert.equal(roundTrip('| A | B |\n| --- | --- |\n| 1 | 2 |'), '| A   | B   |\n| --- | --- |\n| 1   | 2   |');
});

test('tier-3: GFM task marker is kept as literal text (no TaskList node)', () => {
    // The doc keeps the marker verbatim (parsing must not drop it), and the server — which also has no
    // task-list extension — renders `- [ ] x` as the literal `[ ] x`.
    assert.equal(docText('- [ ] task'), '[ ] task');
    assert.equal(docText('- [x] done'), '[x] done');
    // Serialized form escapes the brackets (`- \[ \] task`); the server renders that and `- [ ] task`
    // to the identical visible `[ ] task` (verified against app/Support/MarkdownText.php), so escaping
    // is not content loss.
    assert.equal(roundTrip('- [ ] task'), '- \\[ \\] task');
    assert.equal(roundTrip('- [x] done'), '- \\[x\\] done');
});

test('tier-3 (gate b): image source preserved, alt/title round-trip, syntax kept safe', () => {
    // Plain image and a title round-trip exactly.
    assert.equal(roundTrip('![alt](https://example.com/x.png)'), '![alt](https://example.com/x.png)');
    assert.equal(roundTrip('![alt](https://example.com/x.png "cap")'), '![alt](https://example.com/x.png "cap")');
    // An escaped `]` in the alt stays escaped, so it can't close the alt early and expose image syntax.
    assert.equal(roundTrip('![a\\]b](https://example.com/a.png "cap")'), '![a\\]b](https://example.com/a.png "cap")');
    // `<tag>` in the alt is entity-encoded (no raw `<tag`, gate c) — not exact, but the server renders
    // `![&lt;b&gt;x]` and `![<b>x]` to the identical visible alt (verified against MarkdownText.php).
    assert.equal(roundTrip('![<b>x](https://e.com/y.png)'), '![&lt;b&gt;x](https://e.com/y.png)');
});

test('tier-3: Japanese text adjacent to a URL', () => {
    assert.equal(roundTrip('日本語 https://example.com です'), '日本語 [https://example.com](https://example.com) です');
});

test('tier-3: non-http link schemes are preserved by the editor (server drops them on render)', () => {
    // The editor restricts only what the toolbar/autolink CREATE; a link already in the Markdown is
    // kept verbatim. The server sanitizer (allowLinkSchemes http/https) is the boundary that strips.
    assert.equal(roundTrip('[m](mailto:a@b.com)'), '[m](mailto:a@b.com)');
    assert.equal(roundTrip('[j](javascript:alert(1))'), '[j](javascript:alert(1))');
    assert.equal(roundTrip('[r](/relative/path)'), '[r](/relative/path)');
});

test('tier-3 (gate a): raw inline HTML stays literal, serialized as escaped entities', () => {
    assert.equal(roundTrip('<b>x</b>'), '&lt;b&gt;x&lt;/b&gt;');
    assert.equal(roundTrip('<strong>x</strong>'), '&lt;strong&gt;x&lt;/strong&gt;');
});

test('tier-3: escaped pipe in a table cell survives (structure + cell text preserved)', () => {
    const md = '| a \\| b | c |\n| --- | --- |\n| 1 | 2 |';
    // The input parses to a 2-column table whose first cell text is `a | b`.
    assert.deepEqual(firstTableRowCells(md), ['a | b', 'c']);
    const once = roundTrip(md);
    // The serialized output re-parses to the SAME structure — so the server parses the same table
    // instead of degrading it to a paragraph — and is idempotent on a second pass.
    assert.deepEqual(firstTableRowCells(once), ['a | b', 'c']);
    assert.equal(roundTrip(once), once);
});

// ─── tier 4: no raw HTML in serialized output (gate c) ───────────────────────────────────────────

const programmaticDocs: Array<[string, (e: Editor) => void]> = [
    ['bold', (e) => e.chain().insertContent('x').selectAll().toggleBold().run()],
    ['italic', (e) => e.chain().insertContent('x').selectAll().toggleItalic().run()],
    ['strike', (e) => e.chain().insertContent('x').selectAll().toggleStrike().run()],
    ['inline code', (e) => e.chain().insertContent('x').selectAll().toggleCode().run()],
    ['bullet list', (e) => e.chain().insertContent('x').toggleBulletList().run()],
    ['ordered list', (e) => e.chain().insertContent('x').toggleOrderedList().run()],
    ['blockquote', (e) => e.chain().insertContent('x').toggleBlockquote().run()],
    ['heading', (e) => e.chain().insertContent('x').toggleHeading({ level: 2 }).run()],
    ['code block', (e) => e.chain().insertContent('x').toggleCodeBlock().run()],
    ['link', (e) => e.chain().insertContent('x').selectAll().setLink({ href: 'https://x.com' }).run()],
    ['horizontal rule', (e) => e.chain().insertContent('x').setHorizontalRule().run()],
    ['table', (e) => e.chain().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run()],
];

for (const [name, build] of programmaticDocs) {
    test(`tier-4 no raw HTML (command: ${name})`, () => {
        const e = makeEditor();
        build(e);
        const md = serializeMarkdown(e);
        e.destroy();
        assert.ok(!hasRawHtmlTag(md), `unexpected raw tag in ${JSON.stringify(md)}`);
    });
}

for (const md of [
    '<b>x</b>',
    '<strong>x</strong>',
    'a <em>b</em> c',
    '![alt](https://example.com/x.png)',
    '![<b>x](https://e.com/y.png)', // <tag> in image alt
    '![k](https://e.com/x.png "t<i>")', // <tag> in image title
    '- [ ] task',
    '[j](javascript:alert(1))',
    'https://example.com',
]) {
    test(`tier-4 no raw HTML (parse path: ${JSON.stringify(md)})`, () => {
        assert.ok(!hasRawHtmlTag(roundTrip(md)), `unexpected raw tag from ${JSON.stringify(md)}`);
    });
}
