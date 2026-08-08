import assert from 'node:assert/strict';
import test from 'node:test';
import {
    dismissProgress,
    dismissVars,
    dismissVisual,
    flightOut,
    flightTo,
    flightVars,
    gestureMode,
    lockAxis,
    outwardDisplacement,
    pageVars,
    shouldDismiss,
    snapTarget,
    velocityFrom,
} from './use-swipe-deck.ts';

test('a drag shorter than the slop has not picked an axis', () => {
    assert.equal(lockAxis(0, 0), null);
    assert.equal(lockAxis(9, 9), null);
    assert.equal(lockAxis(-9, 5), null);
});

test('the dominant axis wins once the slop is cleared', () => {
    assert.equal(lockAxis(20, 5), 'x');
    assert.equal(lockAxis(-20, 5), 'x');
    assert.equal(lockAxis(5, 20), 'y');
    assert.equal(lockAxis(5, -20), 'y');
});

test('a diagonal drag does not turn the page', () => {
    assert.equal(lockAxis(20, 20), 'y');
    assert.equal(lockAxis(-20, 20), 'y');
});

test('the slop is the first distance that counts, not the first that exceeds', () => {
    assert.equal(lockAxis(10, 0), 'x');
    assert.equal(lockAxis(9, 0), null);
});

test('a mostly-vertical drag stays vertical however far it goes sideways', () => {
    assert.equal(lockAxis(40, 200), 'y');
});

test('a horizontal drag in the middle of the deck turns the page either way', () => {
    assert.equal(gestureMode('x', -50, 1, 3), 'page');
    assert.equal(gestureMode('x', 50, 1, 3), 'page');
});

test('dragging off either end closes instead of paging', () => {
    assert.equal(gestureMode('x', 50, 0, 3), 'dismiss');
    assert.equal(gestureMode('x', -50, 0, 3), 'page');
    assert.equal(gestureMode('x', -50, 2, 3), 'dismiss');
    assert.equal(gestureMode('x', 50, 2, 3), 'page');
});

test('a lone image is off the end in both directions', () => {
    assert.equal(gestureMode('x', 50, 0, 1), 'dismiss');
    assert.equal(gestureMode('x', -50, 0, 1), 'dismiss');
});

test('a vertical drag closes wherever it starts from', () => {
    assert.equal(gestureMode('y', 0, 1, 3), 'dismiss');
    assert.equal(gestureMode('y', 0, 0, 3), 'dismiss');
});

test('a vertical dismiss counts travel in both directions', () => {
    assert.equal(outwardDisplacement(120, null), 120);
    assert.equal(outwardDisplacement(-120, null), -120);
});

test('a sideways dismiss only counts travel the way it left the deck', () => {
    assert.equal(outwardDisplacement(120, 1), 120);
    assert.equal(outwardDisplacement(-120, 1), 0, 'heading back towards the images is not heading out');
    assert.equal(outwardDisplacement(-120, -1), -120);
    assert.equal(outwardDisplacement(120, -1), 0);
});

test('reversing a sideways dismiss past the origin closes nothing', () => {
    // Off the first image to the right, then dragged well back to the left: the picture holds at the
    // origin, nothing previews a close, and the release has no travel to commit.
    const effective = outwardDisplacement(-160, 1);
    const progress = dismissProgress(effective, 253);
    assert.equal(effective, 0);
    assert.equal(progress, 0);
    assert.deepEqual(dismissVisual(progress), { scale: 1, scrimOpacity: 1, chromeOpacity: 1 });
    assert.equal(shouldDismiss(progress, -1.5, effective), false, 'a fast reversal is still a reversal');
});

test('dismiss progress runs from nothing to saturated', () => {
    assert.equal(dismissProgress(0, 200), 0);
    assert.equal(dismissProgress(100, 200), 0.5);
    assert.equal(dismissProgress(400, 200), 1);
    assert.equal(dismissProgress(-100, 200), 0.5, 'direction does not change how far along it is');
    assert.equal(dismissProgress(100, 0), 0, 'a zero-sized viewport cannot be crossed');
});

test('the closing picture starts as the resting one and only moves one way', () => {
    const rest = dismissVisual(0);
    assert.deepEqual(rest, { scale: 1, scrimOpacity: 1, chromeOpacity: 1 });

    const full = dismissVisual(1);
    assert.equal(full.scale, 0.8);
    assert.equal(full.scrimOpacity, 0.4);
    assert.equal(full.chromeOpacity, 0, 'chrome is gone well before the release');

    const mid = dismissVisual(0.5);
    assert.ok(mid.scale < rest.scale && mid.scale > full.scale);
    assert.ok(mid.scrimOpacity < rest.scrimOpacity && mid.scrimOpacity > full.scrimOpacity);
});

test('chrome clears out in the first third of the drag', () => {
    assert.equal(dismissVisual(0.34).chromeOpacity, 0);
    assert.ok(dismissVisual(0.2).chromeOpacity > 0);
});

test('a short slow drag does not close', () => {
    assert.equal(shouldDismiss(0.2, 0.1, 40), false);
});

test('crossing the distance closes regardless of speed', () => {
    assert.equal(shouldDismiss(0.35, 0, 100), true);
    assert.equal(shouldDismiss(0.9, 0, 250), true);
});

test('a fast flick closes from a short drag', () => {
    assert.equal(shouldDismiss(0.1, 0.9, 30), true);
    assert.equal(shouldDismiss(0.1, -0.9, -30), true);
});

test('a flick too short to be aimed does not close', () => {
    assert.equal(shouldDismiss(0.05, 0.9, 10), false);
});

test('pulling back before letting go does not close', () => {
    assert.equal(shouldDismiss(0.2, -0.9, 60), false, 'velocity opposes the drag');
});

test('a page drag that falls short stays put', () => {
    assert.equal(snapTarget({ index: 1, count: 3, dx: -40, velocity: 0, width: 390 }), 1);
});

test('the page distance is the boundary, not just past it', () => {
    assert.equal(snapTarget({ index: 0, count: 3, dx: -78, velocity: 0, width: 390 }), 1);
    assert.equal(snapTarget({ index: 0, count: 3, dx: -77, velocity: 0, width: 390 }), 0);
});

test('the distance to commit scales with the viewport', () => {
    assert.equal(snapTarget({ index: 0, count: 3, dx: -100, velocity: 0, width: 390 }), 1);
    assert.equal(snapTarget({ index: 0, count: 3, dx: -100, velocity: 0, width: 1280 }), 0);
});

test('a fast flick turns the page from a short drag', () => {
    assert.equal(snapTarget({ index: 0, count: 3, dx: -20, velocity: -0.8, width: 390 }), 1);
});

test('a flick under the aim floor does not turn the page', () => {
    assert.equal(snapTarget({ index: 0, count: 3, dx: -10, velocity: -0.8, width: 390 }), 0);
});

test('a flick back the way it came does not turn the page', () => {
    assert.equal(snapTarget({ index: 0, count: 3, dx: -20, velocity: 0.8, width: 390 }), 0);
});

test('the deck does not wrap at either end', () => {
    assert.equal(snapTarget({ index: 0, count: 3, dx: 200, velocity: 1, width: 390 }), 0);
    assert.equal(snapTarget({ index: 2, count: 3, dx: -200, velocity: -1, width: 390 }), 2);
    assert.equal(snapTarget({ index: 0, count: 1, dx: -200, velocity: -1, width: 390 }), 0);
});

test('a real recorded swipe commits', () => {
    assert.equal(snapTarget({ index: 0, count: 3, dx: -140, velocity: -1.46, width: 390 }), 1);
});

test('velocity needs two samples to exist', () => {
    assert.equal(velocityFrom([]), 0);
    assert.equal(velocityFrom([{ s: 10, t: 5 }]), 0);
});

test('velocity is the tail displacement over the tail time', () => {
    assert.equal(velocityFrom([{ s: 0, t: 0 }, { s: 60, t: 60 }]), 1);
    assert.equal(velocityFrom([{ s: 0, t: 0 }, { s: -60, t: 60 }]), -1);
});

test('samples older than the window are not averaged in', () => {
    // The first leg was slow, the last 100ms fast; only the last should show.
    const v = velocityFrom([
        { s: 0, t: 0 },
        { s: 10, t: 500 },
        { s: 110, t: 550 },
    ]);
    assert.equal(v, 2);
});

test('a drag that stopped before the release has no speed left', () => {
    const v = velocityFrom([
        { s: 0, t: 0 },
        { s: 200, t: 100 },
        { s: 200, t: 400 },
    ]);
    assert.equal(v, 0, 'the release sample is alone in the window');
});

test('a release with no movement has no speed', () => {
    assert.equal(velocityFrom([{ s: 0, t: 0 }, { s: 0, t: 20 }]), 0);
});

test('samples sharing a timestamp do not divide by zero', () => {
    const v = velocityFrom([{ s: 0, t: 40 }, { s: 30, t: 40 }]);
    assert.equal(v, 0);
    assert.ok(Number.isFinite(v));
});

test('a picture already sitting on its thumbnail has nowhere to fly', () => {
    const box = { x: 100, y: 100, width: 50, height: 50 };
    assert.deepEqual(flightTo(box, box, { x: 0, y: 0, width: 400, height: 400 }), { x: 0, y: 0, scale: 1 });
});

test('the flight lands the picture centred on its thumbnail', () => {
    const from = { x: 0, y: 100, width: 200, height: 100 };
    const to = { x: 300, y: 300, width: 50, height: 50 };
    const stage = { x: 0, y: 0, width: 400, height: 400 };
    const { x, y, scale } = flightTo(from, to, stage);

    // Replay the transform the stage will apply and check where the picture's centre ends up.
    const cx = stage.x + stage.width / 2;
    const cy = stage.y + stage.height / 2;
    const landedX = cx + scale * (from.x + from.width / 2 - cx) + x;
    const landedY = cy + scale * (from.y + from.height / 2 - cy) + y;
    assert.equal(landedX, to.x + to.width / 2);
    assert.equal(landedY, to.y + to.height / 2);
});

test('the flight shrinks to fit inside the thumbnail, not to overflow it', () => {
    // A wide picture into a square crop: matching the width would still be taller than the box.
    const { scale } = flightTo({ x: 0, y: 0, width: 390, height: 250 }, { x: 0, y: 0, width: 112, height: 112 }, { x: 0, y: 0, width: 390, height: 844 });
    // A tenth of a pixel of slack: the scale is rounded for a stable CSS string, and at these sizes
    // that rounding is worth more than an exact fit.
    assert.ok(scale * 390 <= 112.1, `width ${scale * 390}`);
    assert.ok(scale * 250 <= 112.1, `height ${scale * 250}`);
});

test('a picture with no size does not divide by it', () => {
    const flight = flightTo({ x: 0, y: 0, width: 0, height: 0 }, { x: 10, y: 10, width: 50, height: 50 }, { x: 0, y: 0, width: 400, height: 400 });
    assert.deepEqual(flight, { x: 0, y: 0, scale: 1 });
});

test('with nowhere to fly the picture leaves the way it was pushed, at full size', () => {
    assert.deepEqual(flightOut('y', 100, 250), { x: 0, y: 500, scale: 1 });
    assert.deepEqual(flightOut('y', -100, 250), { x: 0, y: -500, scale: 1 });
    assert.deepEqual(flightOut('x', -100, 120), { x: -240, y: 0, scale: 1 });
});

test('the flight fades the picture, the scrim and the chrome out together', () => {
    const vars = flightVars({ x: -107, y: -116, scale: 0.2872 });
    assert.equal(vars['--lb-dismiss-x'], '-107px');
    assert.equal(vars['--lb-dismiss-y'], '-116px');
    assert.equal(vars['--lb-scale'], '0.2872');
    assert.equal(vars['--lb-stage-opacity'], '0');
    assert.equal(vars['--lb-scrim-opacity'], '0');
    assert.equal(vars['--lb-chrome-opacity'], '0');
});

test('the resting variables are the identity for both modes', () => {
    assert.equal(pageVars(0)['--lb-drag-x'], '0px');
    const rest = dismissVars('y', 0, dismissVisual(0));
    assert.equal(rest['--lb-dismiss-y'], '0px');
    assert.equal(rest['--lb-scale'], '1');
    assert.equal(rest['--lb-scrim-opacity'], '1');
});

test('a negative zero offset is still written as zero', () => {
    assert.equal(pageVars(-0)['--lb-drag-x'], '0px');
});

test('the drag axis is the only one that moves', () => {
    const vertical = dismissVars('y', -120, dismissVisual(0.5));
    assert.equal(vertical['--lb-dismiss-x'], '0px');
    assert.equal(vertical['--lb-dismiss-y'], '-120px');

    const horizontal = dismissVars('x', 90, dismissVisual(0.5));
    assert.equal(horizontal['--lb-dismiss-x'], '90px');
    assert.equal(horizontal['--lb-dismiss-y'], '0px');
});

test('equal input gives byte-equal output so a repaint cannot restart the transition', () => {
    assert.deepEqual(pageVars(12.3456789), pageVars(12.3456789));
    assert.deepEqual(dismissVars('y', 40 / 3, dismissVisual(1 / 3)), dismissVars('y', 40 / 3, dismissVisual(1 / 3)));
    for (const value of Object.values(dismissVars('y', 40 / 3, dismissVisual(1 / 3)))) {
        assert.ok(!/\d{6,}/.test(value), `float noise leaked into ${value}`);
    }
});
