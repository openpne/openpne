/*
 * OpenPNE 3's jquery.timeago for the Classic timeline: every span.timeago[data-datetime] reads as
 * "N分前" on the rung the plugin used, counted again each minute. The words come from the page
 * (#classic-timeago-strings, the same catalog as everything else on it); the span already holds the
 * absolute datetime, which is what stays when nothing here runs or the value does not parse.
 */
(function () {
    'use strict';

    /**
     * The words for a distance, on OpenPNE 3's ladder (jquery.timeago inWords, allowFuture off: a
     * future date reads by its distance, "ago"). Null for a distance that is not a number, so the
     * caller leaves the absolute text alone.
     */
    function relativeLabel(distanceMs, strings) {
        if (typeof distanceMs !== 'number' || !isFinite(distanceMs)) {
            return null;
        }
        var seconds = Math.abs(distanceMs) / 1000;
        var minutes = seconds / 60;
        var hours = minutes / 60;
        var days = hours / 24;
        var years = days / 365;

        function words(key, count) {
            return String(strings[key] || '').replace(':count', String(count));
        }

        // seconds < 45 and < 90 both said "1分" in OpenPNE 3's ja strings.
        if (seconds < 90) return words('minute', 1);
        if (minutes < 45) return words('minutes', Math.round(minutes));
        if (minutes < 90) return words('hour', 1);
        if (hours < 24) return words('hours', Math.round(hours));
        if (hours < 42) return words('day', 1);
        if (days < 30) return words('days', Math.round(days));
        if (days < 45) return words('month', 1);
        if (days < 365) return words('months', Math.round(days / 30));
        if (years < 1.5) return words('year', 1);

        return words('years', Math.round(years));
    }

    if (typeof module !== 'undefined') {
        module.exports = { relativeLabel: relativeLabel };

        return;
    }

    var INSERTED = 'classic-timeline:inserted';
    var TICK = 60000;

    function strings() {
        var node = document.getElementById('classic-timeago-strings');
        if (!node) {
            return null;
        }
        try {
            return JSON.parse(node.textContent);
        } catch (invalid) { // eslint-disable-line @typescript-eslint/no-unused-vars -- ES5 syntax: no optional catch binding
            return null;
        }
    }

    var WORDS = strings();
    if (!WORDS) {
        return;
    }

    function within(root, selector) {
        var found = root.matches && root.matches(selector) ? [root] : [];
        var nodes = root.querySelectorAll(selector);
        var i;
        for (i = 0; i < nodes.length; i += 1) {
            found.push(nodes[i]);
        }

        return found;
    }

    function apply(root) {
        var spans = within(root, '.timeago[data-datetime]');
        var now = Date.now();
        var i;
        var at;
        var label;
        for (i = 0; i < spans.length; i += 1) {
            at = Date.parse(spans[i].getAttribute('data-datetime'));
            label = relativeLabel(now - at, WORDS);
            if (label !== null) {
                spans[i].textContent = label;
            }
        }
    }

    document.addEventListener(INSERTED, function (event) {
        apply(event.detail && event.detail.root ? event.detail.root : document);
    });

    window.setInterval(function () {
        apply(document);
    }, TICK);

    apply(document);
})();
