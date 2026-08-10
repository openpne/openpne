import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    type DateFormatContext,
    formatAbsolute,
    formatCivilDate,
    formatCivilMonth,
    formatCivilMonthShort,
    formatExact,
    formatListStamp,
    siteCurrentYear,
} from './date.ts';

const tokyo: DateFormatContext = { locale: 'ja-JP', timeZone: 'Asia/Tokyo' };
const newYork: DateFormatContext = { locale: 'ja-JP', timeZone: 'America/New_York' };

// 15:05Z is the next calendar day in Tokyo and the same day in New York — the case that used to
// render differently per viewer because the browser's zone decided.
const evening = '2026-08-09T15:05:16+00:00';

test('an instant renders in the site timezone, not the viewer\'s', () => {
    assert.equal(formatAbsolute(evening, tokyo), '2026年8月10日 00:05');
    assert.equal(formatAbsolute(evening, newYork), '2026年8月9日 11:05');
});

test('a display never shows seconds, and the hour is always two digits', () => {
    assert.equal(formatAbsolute('2026-08-09T15:05:16+00:00', tokyo), '2026年8月10日 00:05');
    assert.equal(formatAbsolute('2026-08-10T00:05:16+00:00', tokyo), '2026年8月10日 09:05');
    assert.equal(formatListStamp('2026-08-09T15:05:16+00:00', tokyo, new Date('2026-08-09T15:30:00Z')), '00:05');
});

test('the locale comes from the site, not the browser', () => {
    assert.equal(formatAbsolute(evening, { locale: 'en-US', timeZone: 'Asia/Tokyo' }), 'August 10, 2026 at 00:05');
    assert.equal(formatCivilDate('2026-08-10', { locale: 'en-US', timeZone: 'Asia/Tokyo' }), 'August 10, 2026');
});

test('a list stamp shows time for today, date for this year, year for anything older', () => {
    const now = new Date('2026-08-09T15:30:00Z'); // 2026-08-10 00:30 in Tokyo

    assert.equal(formatListStamp('2026-08-09T15:10:00Z', tokyo, now), '00:10'); // 20 minutes ago, same Tokyo day
    assert.equal(formatListStamp('2026-08-09T14:00:00Z', tokyo, now), '8月9日'); // 90 minutes ago, previous Tokyo day
    assert.equal(formatListStamp('2025-12-31T14:00:00Z', tokyo, now), '2025年12月31日');
});

// The day and year boundaries are the site's, so the same row reads the same for every viewer.
test('the boundaries a list stamp turns on are the site\'s calendar, not the viewer\'s', () => {
    const now = new Date('2026-08-09T15:30:00Z');

    // Still 2026-08-09 in New York, so "today" there — but the site is in Tokyo, where it is the 10th.
    assert.equal(formatListStamp('2026-08-09T14:00:00Z', tokyo, now), '8月9日');
    assert.equal(formatListStamp('2026-08-09T14:00:00Z', newYork, now), '10:00');

    // 2026-01-01 00:30 in Tokyo is still 2025 in New York.
    const newYear = new Date('2025-12-31T15:30:00Z');
    assert.equal(formatListStamp('2025-12-31T16:00:00Z', tokyo, newYear), '01:00');
    assert.equal(siteCurrentYear(tokyo, newYear), 2026);
    assert.equal(siteCurrentYear(newYork, newYear), 2025);
});

test('a civil date is the stored day in every timezone, with the weekday only when asked', () => {
    for (const context of [tokyo, newYork, { locale: 'ja-JP', timeZone: 'UTC' }]) {
        assert.equal(formatCivilDate('2026-08-10', context), '2026年8月10日');
    }

    assert.equal(formatCivilDate('2026-08-10', tokyo, true), '2026年8月10日(月)');
});

// What the abbreviated shape stands in for, so the title is never the same string as the text.
test('the exact value names the second and the zone it was read in', () => {
    assert.equal(formatExact(evening, tokyo), '2026年8月10日 00:05:16');
    assert.equal(formatExact(evening, newYork), '2026年8月9日 11:05:16');
    assert.notEqual(formatExact(evening, tokyo), formatAbsolute(evening, tokyo));
});

test('month labels are civil, so a year-end month keeps its year in any timezone', () => {
    assert.equal(formatCivilMonth(2026, 1, newYork), '2026年1月');
    assert.equal(formatCivilMonth(2026, 12, newYork), '2026年12月');
    assert.equal(formatCivilMonthShort(7, newYork), '7月');
    assert.equal(formatCivilMonth(2026, 1, { locale: 'en-US', timeZone: 'America/New_York' }), 'January 2026');
});

// The notification feed serializes a null created_at as '', so a formatter that throws on an
// unrepresentable date would take the page down instead of spoiling one row.
test('an unusable value falls back to itself instead of throwing', () => {
    assert.equal(formatAbsolute('', tokyo), '');
    assert.equal(formatListStamp('', tokyo), '');
    assert.equal(formatExact('', tokyo), '');
    assert.equal(formatCivilDate('not-a-date', tokyo), 'not-a-date');
});

test('an instant handed to the civil formatter shows up verbatim, never shifted a day', () => {
    assert.equal(formatCivilDate(evening, tokyo), evening);
});

// Date.UTC would roll these into a neighbouring month/year, which is exactly the silently-wrong
// rendering the civil formatter exists to prevent.
test('a date that parses but does not exist is not rolled into the next month', () => {
    assert.equal(formatCivilDate('2026-02-31', tokyo), '2026-02-31');
    assert.equal(formatCivilDate('2026-13-01', tokyo), '2026-13-01');
    assert.equal(formatCivilDate('2026-00-10', tokyo), '2026-00-10');
    assert.equal(formatCivilDate('0026-08-10', tokyo), '0026-08-10'); // Date.UTC would read this as 1926
});
