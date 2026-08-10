import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    type DateFormatContext,
    formatCivilDate,
    formatCivilMonth,
    formatCivilMonthShort,
    formatInstant,
    formatInstantDate,
    siteCurrentYear,
} from './date.ts';

const tokyo: DateFormatContext = { locale: 'ja-JP', timeZone: 'Asia/Tokyo' };
const newYork: DateFormatContext = { locale: 'ja-JP', timeZone: 'America/New_York' };

// 15:05Z is the next calendar day in Tokyo and the same day in New York — the case that used to
// render differently per viewer because the browser's zone decided.
const evening = '2026-08-09T15:05:16+00:00';

test('an instant renders in the site timezone, not the viewer\'s', () => {
    assert.equal(formatInstant(evening, tokyo), '2026/8/10 0:05:16');
    assert.equal(formatInstant(evening, newYork), '2026/8/9 11:05:16');
});

test('an instant reduced to a day uses the site timezone for the day boundary', () => {
    assert.equal(formatInstantDate(evening, tokyo), '2026/8/10');
    assert.equal(formatInstantDate(evening, newYork), '2026/8/9');
});

test('a civil date is the stored day in every timezone', () => {
    for (const context of [tokyo, newYork, { locale: 'ja-JP', timeZone: 'UTC' }]) {
        assert.equal(formatCivilDate('2026-08-10', context), '2026/8/10');
    }
});

test('the locale comes from the site, not the browser', () => {
    assert.equal(formatInstantDate(evening, { locale: 'en-US', timeZone: 'Asia/Tokyo' }), '8/10/2026');
    assert.equal(formatCivilDate('2026-08-10', { locale: 'en-US', timeZone: 'Asia/Tokyo' }), '8/10/2026');
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
    assert.equal(formatInstant('', tokyo), '');
    assert.equal(formatInstantDate('', tokyo), '');
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

test('the current year is the site\'s, not the viewer\'s, across the new year', () => {
    // 2026-01-01 00:30 in Tokyo is still 2025-12-31 in New York.
    const newYear = new Date('2025-12-31T15:30:00Z');

    assert.equal(siteCurrentYear(tokyo, newYear), 2026);
    assert.equal(siteCurrentYear(newYork, newYear), 2025);
});
