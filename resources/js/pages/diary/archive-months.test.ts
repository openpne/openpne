import assert from 'node:assert/strict';
import { test } from 'node:test';
import { buildArchiveGrid, countBucket, selectedBeyondRecentYears } from './archive-months.ts';

test('zero-fills each year to twelve month cells', () => {
    const rows = buildArchiveGrid([{ year: 2026, month: 3, count: 4 }], 2026, 7);
    assert.equal(rows.length, 1);

    const row = rows[0];
    assert.ok(row);
    assert.equal(row.year, 2026);
    assert.equal(row.months.length, 12);
    assert.deepEqual(row.months[2], { month: 3, count: 4, href: '/diary/listMember/7/2026/3' });
    assert.deepEqual(row.months[0], { month: 1, count: 0, href: null });
});

test('spans from the earliest counted year through the current year, newest first', () => {
    const rows = buildArchiveGrid([{ year: 2024, month: 5, count: 1 }], 2026, 7);
    assert.deepEqual(
        rows.map((r) => r.year),
        [2026, 2025, 2024],
    );
});

test('current-year months past the latest entry are zero and non-linked', () => {
    // Only January 2026 has entries; the rest of the current year has no counts.
    const rows = buildArchiveGrid([{ year: 2026, month: 1, count: 2 }], 2026, 7);
    const row = rows[0];
    assert.ok(row);
    assert.deepEqual(row.months[11], { month: 12, count: 0, href: null });
});

test('builds an archive href for counted months only', () => {
    const rows = buildArchiveGrid([{ year: 2026, month: 3, count: 4 }], 2026, 42);
    const row = rows[0];
    assert.ok(row);
    assert.equal(row.months[2]?.href, '/diary/listMember/42/2026/3');
    assert.equal(row.months[3]?.href, null); // April: no entries
});

test('count buckets use fixed thresholds 0 / 1-2 / 3-5 / 6-9 / 10+', () => {
    assert.equal(countBucket(0), 0);
    assert.equal(countBucket(1), 1);
    assert.equal(countBucket(2), 1);
    assert.equal(countBucket(3), 2);
    assert.equal(countBucket(5), 2);
    assert.equal(countBucket(6), 3);
    assert.equal(countBucket(9), 3);
    assert.equal(countBucket(10), 4);
    assert.equal(countBucket(999), 4);
});

test('returns an empty grid when there are no counts', () => {
    assert.deepEqual(buildArchiveGrid([], 2026, 7), []);
});

test('a selection in a folded older year forces the grid open', () => {
    // 2024-2026: two recent rows visible, 2024 folded.
    const rows = buildArchiveGrid([{ year: 2024, month: 3, count: 2 }], 2026, 7);
    assert.equal(selectedBeyondRecentYears(rows, { year: 2024 }, 2), true);
    assert.equal(selectedBeyondRecentYears(rows, { year: 2026 }, 2), false); // recent row
    assert.equal(selectedBeyondRecentYears(rows, { year: 2025 }, 2), false); // recent row
    assert.equal(selectedBeyondRecentYears(rows, null, 2), false); // unfiltered
});
