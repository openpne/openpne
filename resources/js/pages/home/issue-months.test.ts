import assert from 'node:assert/strict';
import { test } from 'node:test';
import { buildMonthRows } from '../../lib/month-grid.ts';
import { issueBucket, monthHref } from './issue-months.ts';

test('a month links to its padded URL', () => {
    assert.equal(monthHref(2026, 9), '/home/2026/09');
    assert.equal(monthHref(2026, 12), '/home/2026/12');
});

test('a month of issues is bucketed by the week', () => {
    assert.deepEqual(
        [0, 1, 7, 8, 15, 16, 23, 24, 31].map(issueBucket),
        [0, 1, 1, 2, 2, 3, 3, 4, 4],
    );
});

test('a month with issues is a link and a month without is not', () => {
    const rows = buildMonthRows(
        [
            { year: 2026, month: 9, count: 22 },
            { year: 2025, month: 12, count: 3 },
        ],
        2026,
        monthHref,
    );

    assert.deepEqual(
        rows.map((row) => row.year),
        [2026, 2025],
    );
    assert.deepEqual(rows[0]?.months[8], { month: 9, count: 22, href: '/home/2026/09' });
    assert.deepEqual(rows[0]?.months[7], { month: 8, count: 0, href: null });
    assert.deepEqual(rows[1]?.months[11], { month: 12, count: 3, href: '/home/2025/12' });
});

test('an empty month the reader is on keeps its year on the grid', () => {
    const rows = buildMonthRows([{ year: 2026, month: 9, count: 1 }], 2026, monthHref, { year: 2024 });

    assert.deepEqual(
        rows.map((row) => row.year),
        [2026, 2025, 2024],
    );
});
