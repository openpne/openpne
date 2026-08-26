/*
 * The OpenPNE 3 inline reply layer for the Classic timeline (timelineTemplate's comment block):
 * open a row's comment box in place, post into it, and pull the earlier comments a row's tail left
 * out. Everything it touches ships as a working link or a working form, so a browser that never runs
 * this — or a fetch that fails — reaches the same places by ordinary navigation.
 *
 * Every lookup is row-local. The OpenPNE 3 ids repeat: two home gadgets both draw the viewer's own
 * post, so `#commentlist-7` is two elements and the one that matters is the one inside the row the
 * click came from.
 */
(function () {
    'use strict';

    var MAXLENGTH = 140;

    // Code points over the body with its newlines normalized the way the server normalizes them
    // (StoreReplyRequest), so this gate, the server's max:140 and the compose counter all measure
    // the same thing. String.length would count an astral emoji as two.
    function bodyLength(value) {
        return Array.from(value.replace(/\r\n?/g, '\n')).length;
    }

    function canSubmit(value) {
        var length = bodyLength(value);

        return length > 0 && length <= MAXLENGTH;
    }

    /**
     * What to tell the member when a reply is refused. Only the validator's line is taken from the
     * payload: `message` is a framework literal, in English whatever the site's language, for the
     * expired session and the rate limiter alike.
     */
    function errorText(status, data, fallback) {
        if (status === 422 && data && data.errors && data.errors.body && data.errors.body[0]) {
            return data.errors.body[0];
        }

        return fallback;
    }

    // `node --test` evaluates this file with a `module` in scope and takes the pure half alone.
    if (typeof module !== 'undefined') {
        module.exports = { bodyLength: bodyLength, canSubmit: canSubmit, errorText: errorText };

        return;
    }

    if (!window.fetch) {
        return; // every control is a link or a form of its own without us
    }

    var INSERTED = 'classic-timeline:inserted';

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');

        return meta ? meta.getAttribute('content') : '';
    }

    /** $selector within $root, plus $root itself when it matches (an inserted node can be one). */
    function within(root, selector) {
        var found = root.matches && root.matches(selector) ? [root] : [];
        var nodes = root.querySelectorAll(selector);
        var i;
        for (i = 0; i < nodes.length; i += 1) {
            found.push(nodes[i]);
        }

        return found;
    }

    function announceInserted(root) {
        document.dispatchEvent(new CustomEvent(INSERTED, { detail: { root: root } }));
    }

    /** The comment box of the row an element sits in, or null when that row has none. */
    function replyForm(element) {
        var row = element.closest ? element.closest('.timeline-post') : null;

        return row ? row.querySelector('[data-timeline-reply]') : null;
    }

    function syncButton(form) {
        var input = form.querySelector('.timeline-post-comment-form-input');
        var button = form.querySelector('button[type="submit"]');
        if (input && button) {
            button.disabled = form.getAttribute('data-pending') === 'true' || !canSubmit(input.value);
        }
    }

    /**
     * OpenPNE 3 opened the box on rows that already had comments and left it closed elsewhere until
     * コメントする was clicked; the load-more control appears only once the layer is live, since
     * without it the link is a plain jump to the thread.
     */
    function apply(root) {
        var forms = within(root, '[data-timeline-reply]');
        var links = within(root, '.timeline-comment-loadmore');
        var i;
        for (i = 0; i < forms.length; i += 1) {
            syncButton(forms[i]);
            if (forms[i].parentNode.querySelector('.timeline-post-comment')) {
                forms[i].classList.add('comment-form-show');
            }
        }
        for (i = 0; i < links.length; i += 1) {
            links[i].style.display = 'block';
        }
    }

    document.addEventListener('click', function (event) {
        var link = event.target.closest ? event.target.closest('.timeline-comment-link') : null;
        if (!link) {
            return;
        }
        // The thread page's own row has no inline box: there the link is the same-page jump to the
        // reply form below it, and must stay one.
        var form = replyForm(link);
        if (!form) {
            return;
        }
        event.preventDefault();
        form.classList.add('comment-form-show');
        var input = form.querySelector('.timeline-post-comment-form-input');
        if (input) {
            input.focus();
        }
    });

    document.addEventListener('input', function (event) {
        var input = event.target;
        if (!input.classList || !input.classList.contains('timeline-post-comment-form-input')) {
            return;
        }
        var form = input.closest ? input.closest('[data-timeline-reply]') : null;
        if (form) {
            syncButton(form);
        }
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form.hasAttribute || !form.hasAttribute('data-timeline-reply')) {
            return;
        }
        event.preventDefault();
        // One reply per submit: a second click while the first is in flight would post it twice.
        if (form.getAttribute('data-pending') === 'true') {
            return;
        }
        var input = form.querySelector('.timeline-post-comment-form-input');
        if (!input || !canSubmit(input.value)) {
            return;
        }

        var comments = form.parentNode;
        var loader = comments.querySelector('.timeline-post-comment-form-loader[role="status"]');
        var error = comments.querySelector('.timeline-post-comment-form-loader[role="alert"]');
        form.setAttribute('data-pending', 'true');
        form.setAttribute('aria-busy', 'true');
        syncButton(form);
        if (loader) loader.style.display = 'block';
        if (error) {
            error.textContent = '';
            error.style.display = 'none';
        }

        var status = 0;
        fetch(form.getAttribute('action'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'body=' + encodeURIComponent(input.value),
        })
            .then(function (response) {
                status = response.status;

                // A refusal answers with JSON too; a session that expired answers with a page.
                return response.json().catch(function () {
                    return null;
                });
            })
            .then(function (data) {
                if (status !== 201 || !data || typeof data.html !== 'string') {
                    throw errorText(status, data, form.getAttribute('data-error-text'));
                }
                form.insertAdjacentHTML('beforebegin', data.html);
                input.value = '';
                announceInserted(comments);
            })
            .catch(function (reason) {
                if (error) {
                    error.textContent = typeof reason === 'string' ? reason : form.getAttribute('data-error-text');
                    error.style.display = 'block';
                }
            })
            // Reached whether the reply landed or not — the lock is what a second submit reads.
            .then(function () {
                form.removeAttribute('data-pending');
                form.setAttribute('aria-busy', 'false');
                if (loader) loader.style.display = 'none';
                syncButton(form);
            });
    });

    document.addEventListener('click', function (event) {
        var link = event.target.closest ? event.target.closest('.timeline-comment-loadmore') : null;
        if (!link) {
            return;
        }
        var row = link.closest('.timeline-post');
        var comments = row ? row.querySelector('.timeline-post-comments') : null;
        var url = link.getAttribute('data-replies-url');
        if (!comments || !url) {
            return; // the href is the thread page
        }
        event.preventDefault();
        var loader = link.querySelector('.timeline-comment-loader');
        if (loader) loader.style.display = 'inline';
        comments.setAttribute('aria-busy', 'true');

        fetch(url, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(function (response) {
                // A session that expired answers with the login page. Inserting that would put a
                // form asking for a password under a post, so only ever accept the fragment.
                var type = response.headers.get('Content-Type') || '';
                if (!response.ok || response.redirected || type.indexOf('text/html') !== 0) {
                    throw new Error('unexpected response');
                }

                return response.text();
            })
            .then(function (html) {
                var rows = comments.querySelectorAll('.timeline-post-comment');
                var form = comments.querySelector('[data-timeline-reply]');
                var i;
                for (i = 0; i < rows.length; i += 1) {
                    comments.removeChild(rows[i]);
                }
                if (form) {
                    form.insertAdjacentHTML('beforebegin', html);
                } else {
                    comments.insertAdjacentHTML('beforeend', html);
                }
                comments.setAttribute('aria-busy', 'false');
                link.style.display = 'none';
                announceInserted(comments);
            })
            .catch(function () {
                // The control stays where it was, so the reader can try again — or follow it to the
                // thread, which is the same list on a page of its own.
                if (loader) loader.style.display = 'none';
                comments.setAttribute('aria-busy', 'false');
            });
    });

    document.addEventListener(INSERTED, function (event) {
        apply(event.detail && event.detail.root ? event.detail.root : document);
    });

    apply(document);
})();
