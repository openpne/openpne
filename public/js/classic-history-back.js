/**
 * The Classic backLink lines: OpenPNE 3 bound these to history.back(). The anchor ships with a
 * real destination, so a browser that never runs this still goes somewhere sensible; with history
 * to go back to, the browser's own step back is what the label promises. A page reached directly
 * has no history, and falling through to the href beats a link that does nothing.
 */
(function () {
    'use strict';

    /** False for a modified or non-primary click, which the browser answers itself (a new tab, a saved link). */
    function plainClick(event) {
        return !(event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button);
    }

    document.addEventListener('click', function (event) {
        var link = event.target.closest ? event.target.closest('a[data-history-back]') : null;
        if (!link || !plainClick(event) || window.history.length <= 1) {
            return;
        }
        event.preventDefault();
        window.history.back();
    });
})();
