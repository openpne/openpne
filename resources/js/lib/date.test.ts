import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    type DateFormatContext,
    formatCivilDate,
    formatCivilMonth,
    formatCivilMonthShort,
    formatInstant,
    formatInstantDate,
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
