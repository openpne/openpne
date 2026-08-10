/**
 * Re-read a page the browser restored from its back/forward cache.
 *
 * A restored page is the DOM as it was left, not what the server would send now, so a screen whose
 * rows the member changed by leaving it — opening a notification marks it read — comes back saying
 * the old thing. Only such screens load this: the cache is what makes back instant everywhere else,
 * and a page that cannot go stale behind the member's own back has nothing to pay for that with.
 */
(function () {
    'use strict';

    window.addEventListener('pageshow', function (event) {
        // A normal load is already the server's answer; only a restore can be behind it.
        if (event.persisted) {
            window.location.reload();
        }
    });
})();
