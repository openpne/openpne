import assert from 'node:assert/strict';
import test from 'node:test';
import { IDLE, pressReducer, type PressState } from './use-long-press.ts';

const down = (over: Partial<{ pointerType: string; x: number; y: number; primary: boolean }> = {}) =>
    pressReducer(IDLE, { type: 'down', pointerType: 'touch', x: 100, y: 100, primary: true, ...over }).state;

const pending: PressState = down();

test('a finger going down starts a press from where it landed', () => {
    assert.deepEqual(pending, { phase: 'pending', x: 100, y: 100 });
});

test('a cursor never presses, whatever the machine says about its pointer', () => {
    assert.equal(down({ pointerType: 'mouse' }).phase, 'idle');
});

test('a second finger is a pinch, not a press', () => {
    assert.equal(down({ primary: false }).phase, 'idle');
});

test('lifting the finger is a tap: it ends the press without firing', () => {
    const result = pressReducer(pending, { type: 'up' });

    assert.equal(result.state.phase, 'idle');
    assert.equal(result.fire, false);
});

test('the browser taking the gesture over — a scroll — ends the press', () => {
    assert.equal(pressReducer(pending, { type: 'cancel' }).state.phase, 'idle');
});

test('the press survives a shaky finger and gives up past the slop', () => {
    assert.equal(pressReducer(pending, { type: 'move', x: 106, y: 107 }).state.phase, 'pending');
    assert.equal(pressReducer(pending, { type: 'move', x: 100, y: 110 }).state.phase, 'idle');
    assert.equal(pressReducer(pending, { type: 'move', x: 89, y: 100 }).state.phase, 'idle');
});

test('travel is measured from where the finger landed, not from the origin', () => {
    const far = pressReducer(IDLE, { type: 'down', pointerType: 'touch', x: 400, y: 400, primary: true }).state;

    assert.equal(pressReducer(far, { type: 'move', x: 404, y: 402 }).state.phase, 'pending');
});

test('the timer is what makes the press mean something, and only once', () => {
    const fired = pressReducer(pending, { type: 'timer' });

    assert.equal(fired.fire, true);
    assert.equal(fired.state.phase, 'fired');
    assert.equal(pressReducer(fired.state, { type: 'timer' }).fire, false);
});

test('a timer that outlived the press it was armed for fires nothing', () => {
    assert.equal(pressReducer(IDLE, { type: 'timer' }).fire, false);
});

test('a press that landed stays landed until the finger leaves', () => {
    const fired = pressReducer(pending, { type: 'timer' }).state;

    // The sheet is open; a finger drifting over it is not a scroll to give the gesture back for.
    assert.equal(pressReducer(fired, { type: 'move', x: 200, y: 300 }).state.phase, 'fired');
    assert.equal(pressReducer(fired, { type: 'up' }).state.phase, 'idle');
});
