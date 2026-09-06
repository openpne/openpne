import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

/**
 * A Classic script that cancels a click on a link takes only a plain one (docs/internals/
 * classic-compatibility.md, "JavaScript compatibility"); the rest cancel nothing a modified click
 * could open, and say so here.
 */
const PREDICATE = 'event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button';
const NOT_A_LINK = {
    'classic-timeline-more.js': 'a <button>, nothing for a new tab to open',
    'classic-timeline-mention.js': 'cancels mousedown and keydown only',
};

test('every Classic script that cancels a click carries the plain-click predicate or is not driving a link', () => {
    const dir = fileURLToPath(new URL('../../public/js/', import.meta.url));
    const cancelling = readdirSync(dir)
        .filter((name) => /^classic-.*\.js$/.test(name))
        .filter((name) => {
            const source = readFileSync(dir + name, 'utf8');

            return source.includes("addEventListener('click'") && source.includes('preventDefault(');
        });
    assert.ok(cancelling.length >= 5, cancelling.join(', '));

    for (const name of cancelling) {
        const source = readFileSync(dir + name, 'utf8');
        assert.ok(source.includes(PREDICATE) || name in NOT_A_LINK, `${name} cancels a click without the plain-click predicate`);
    }
    for (const name of Object.keys(NOT_A_LINK)) {
        assert.ok(cancelling.includes(name), `${name} no longer cancels a click; drop it from NOT_A_LINK`);
    }
});
