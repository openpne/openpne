/**
 * The Classic header's notification centre: OpenPNE 3 opened its panel in place rather than
 * navigating, and that is the behaviour this restores.
 *
 * Everything here is an enhancement over markup that already works. The trigger ships as a link to
 * the feed and the rows ship as forms, so a browser that never runs this — or fails to fetch —
 * still reaches everything by ordinary navigation. Nothing is written into the page that the
 * server did not render.
 */
(function () {
    'use strict';

    var trigger = document.querySelector('#notificationCenter .ncbuttonLink');
    var panel = document.getElementById('notificationCenterDetail');
    if (!trigger || !panel || !window.fetch) {
        return; // leave the link alone: it goes to the feed
    }

    var loading = document.getElementById('notificationCenterLoading');
    var empty = document.getElementById('notificationCenterError');
    var loaded = false;

    function open() {
        panel.style.display = 'block';
        trigger.setAttribute('aria-expanded', 'true');
        load();
    }

    function close() {
        panel.style.display = 'none';
        trigger.setAttribute('aria-expanded', 'false');
    }

    function isOpen() {
        return trigger.getAttribute('aria-expanded') === 'true';
    }

    function load() {
        if (loaded) {
            return; // as OpenPNE 3 did: fetched once, reopened for free
        }
        loaded = true;

        // Back to waiting, so a retry after a failure does not sit under the last attempt's
        // "nothing here" while its rows are on the way.
        if (loading) loading.style.display = '';
        if (empty) empty.style.display = 'none';

        fetch(trigger.getAttribute('data-notification-center-url'), {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(function (response) {
                // A session that expired answers with the login page. Inserting that would put a
                // form asking for a password inside the header, so only ever accept the fragment
                // this endpoint serves.
                var type = response.headers.get('Content-Type') || '';
                if (!response.ok || response.redirected || type.indexOf('text/html') !== 0) {
                    throw new Error('unexpected response');
                }
                return response.text();
            })
            .then(function (html) {
                if (loading) loading.style.display = 'none';
                if (html.trim() === '') {
                    if (empty) empty.style.display = 'block';
                    return;
                }
                if (empty) empty.style.display = 'none';
                panel.insertAdjacentHTML('beforeend', html);
                bindRows();
            })
            .catch(function () {
                if (loading) loading.style.display = 'none';
                if (empty) empty.style.display = 'block';
                loaded = false; // let the next open try again
            });
    }

    function bindRows() {
        // OpenPNE 3 bound the click to the whole row, not to the text inside it.
        panel.querySelectorAll('.push.nclink').forEach(function (row) {
            row.addEventListener('click', function (event) {
                if (event.target.closest('a')) {
                    return;
                }
                var form = row.querySelector('form');
                if (form) form.submit();
            });
        });

        panel.querySelectorAll('.push_yesno').forEach(function (box) {
            box.querySelectorAll('button[data-accept-url], button[data-reject-url]').forEach(function (button) {
                button.addEventListener('click', function () {
                    decide(box, button.getAttribute('data-accept-url') || button.getAttribute('data-reject-url'));
                });
            });
        });
    }

    function decide(box, url) {
        var buttons = box.querySelectorAll('button');
        buttons.forEach(function (button) {
            button.disabled = true; // a second click would answer a request that is already spent
            button.style.display = 'none';
        });

        // OpenPNE 3 put a spinner here while it waited. Keeping it means the row says something
        // between the click and the answer instead of going blank, and aria-busy says the same
        // thing to a reader that cannot see it.
        var spinner = box.querySelector('.ncfriendloading');
        if (spinner) spinner.style.display = 'block';
        box.setAttribute('aria-busy', 'true');

        var result = box.querySelector('.ncfriendresultmessage');
        var token = document.querySelector('meta[name="csrf-token"]');

        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-CSRF-TOKEN': token ? token.getAttribute('content') : '',
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            },
        })
            .then(function (response) {
                if (!response.ok) throw new Error('unexpected response');
                return response.json();
            })
            .then(function (data) {
                if (spinner) spinner.style.display = 'none';
                box.setAttribute('aria-busy', 'false');
                if (result) {
                    result.textContent = data.message;
                    result.style.display = 'block';
                    // The row that was focused is gone; the outcome takes its place in the reading
                    // order rather than pulling focus out of the panel.
                    result.focus({ preventScroll: true });
                }
            })
            .catch(function () {
                // Say nothing rather than something wrong: the pages state the outcome truthfully.
                if (spinner) spinner.style.display = 'none';
                box.setAttribute('aria-busy', 'false');
                buttons.forEach(function (button) {
                    button.disabled = false;
                    button.style.display = '';
                });
                // No optional chaining anywhere in this file: it ships unbuilt, and a browser that
                // has fetch but cannot parse ?. would die at syntax before the feature gate runs.
                if (buttons[0]) buttons[0].focus({ preventScroll: true });
            });
    }

    trigger.addEventListener('click', function (event) {
        event.preventDefault();
        if (isOpen()) {
            close();
        } else {
            open();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && isOpen()) {
            close();
            trigger.focus();
        }
    });
})();
