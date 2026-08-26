/*
 * OpenPNE 3's もっと読む for the Classic timeline: append the next page of rows in place. The pager
 * beside the list is the way on without this script, and comes back the moment a fetch fails.
 *
 * The pages arrive as the rows the server would have drawn, with the page after them in a Link
 * header; this script inserts them and remembers the header, nothing more.
 */
(function () {
    'use strict';

    /**
     * The URL a Link header names as rel="next", on the page's own origin; null otherwise. A next on
     * another origin is not followed: a header is not a place to send a member's session.
     */
    function nextUrl(linkHeader, base) {
        var parts = (linkHeader || '').split(',');
        var i;
        var match;
        var url;
        for (i = 0; i < parts.length; i += 1) {
            match = /<([^>]+)>\s*;\s*rel="?next"?/.exec(parts[i]);
            if (match) {
                try {
                    url = new URL(match[1], base);
                } catch (invalid) { // eslint-disable-line @typescript-eslint/no-unused-vars -- ES5 syntax: no optional catch binding
                    return null;
                }

                return url.origin === new URL(base).origin ? url.href : null;
            }
        }

        return null;
    }

    if (typeof module !== 'undefined') {
        module.exports = { nextUrl: nextUrl };

        return;
    }

    if (!window.fetch || !window.URL) {
        return;
    }

    var INSERTED = 'classic-timeline:inserted';

    function announceInserted(root) {
        document.dispatchEvent(new CustomEvent(INSERTED, { detail: { root: root } }));
    }

    /**
     * The server pager that belongs to a list: the sibling box after the list's container, where
     * the screens put it. A gadget has none, and takes its failure as a control that stays.
     */
    function pagerFor(container) {
        var parent = container.parentNode;

        return parent ? parent.querySelector('[data-timeline-pager]') : null;
    }

    /** Show the controls and hide the pagers: once we are live the button is the way on. */
    function apply(root) {
        var boxes = root.querySelectorAll('[data-timeline-loadmore-box]');
        var pagers = root.querySelectorAll('[data-timeline-pager]');
        var i;
        for (i = 0; i < boxes.length; i += 1) {
            boxes[i].removeAttribute('hidden');
        }
        for (i = 0; i < pagers.length; i += 1) {
            pagers[i].setAttribute('hidden', '');
        }
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest ? event.target.closest('[data-timeline-loadmore]') : null;
        if (!button || button.getAttribute('data-pending') === 'true') {
            return;
        }
        var box = button.closest('[data-timeline-loadmore-box]');
        var container = button.closest('[data-timeline-container]');
        var list = container ? container.querySelector('#timeline-list') : null;
        var url = button.getAttribute('data-next-url');
        if (!box || !list || !url) {
            return;
        }
        event.preventDefault();
        var loading = box.querySelector('[role="status"]');

        button.setAttribute('data-pending', 'true');
        button.style.display = 'none';
        if (loading) loading.style.display = 'block';
        container.setAttribute('aria-busy', 'true');

        function settle() {
            button.removeAttribute('data-pending');
            if (loading) loading.style.display = 'none';
            container.removeAttribute('aria-busy');
        }

        window.fetch(url, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' }
        })
            .then(function (response) {
                var type = response.headers.get('Content-Type') || '';
                if (!response.ok || response.redirected || type.indexOf('text/html') !== 0) {
                    throw new Error('not a rows fragment');
                }

                return response.text().then(function (html) {
                    return { html: html, next: nextUrl(response.headers.get('Link'), window.location.href) };
                });
            })
            .then(function (page) {
                var last = list.lastElementChild;
                var node;
                list.insertAdjacentHTML('beforeend', page.html);
                for (node = last ? last.nextElementSibling : list.firstElementChild; node; node = node.nextElementSibling) {
                    announceInserted(node);
                }
                settle();
                if (page.next) {
                    button.setAttribute('data-next-url', page.next);
                    button.style.display = '';
                } else {
                    box.parentNode.removeChild(box);
                }
            })
            .catch(function () {
                // Nothing was drawn: the button and the pager come back, and the pager's links are
                // the plain navigation this whole control stands in for.
                settle();
                button.style.display = '';
                var pager = pagerFor(container);
                if (pager) pager.removeAttribute('hidden');
            });
    });

    apply(document);
})();
