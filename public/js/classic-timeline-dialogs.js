/*
 * The Classic timeline's two dialogs: OpenPNE 3's delete confirmation (its colorbox over
 * timelineTemplate's hidden block, here a <dialog> already in the row) and the attached-image
 * lightbox (one dialog for the page). Every trigger is a working link on its own: the confirm page
 * and the full-size file are where they lead without this script.
 *
 * Lookups are row-local: the OpenPNE 3 ids repeat when two home gadgets draw the same post, so the
 * dialog a link names is the one inside the row the click came from.
 */
(function () {
    'use strict';

    if (!window.fetch || typeof window.HTMLDialogElement === 'undefined') {
        return;
    }

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');

        return meta ? meta.getAttribute('content') : '';
    }

    /** The row an element belongs to: a reply row first, the post row otherwise. */
    function rowOf(element) {
        return element.closest('.timeline-post-comment') || element.closest('.timeline-post');
    }

    function open(dialog, trigger) {
        dialog.returnFocusTo = trigger;
        dialog.showModal();
    }

    /** The list a reply row sits in counts its changes; a removal is one, or a list drawn before it lands over the gap. */
    function bumpGeneration(comments) {
        comments.setAttribute('data-generation', String(parseInt(comments.getAttribute('data-generation') || '0', 10) + 1));
    }

    /**
     * Where focus goes once a row is gone: the row's own reply box, else its list. Resolved while the
     * row is still in the tree, since a detached row has no ancestors to ask.
     */
    function focusTargetAfterRemoval(row) {
        var post = row.classList.contains('timeline-post-comment') ? row.closest('.timeline-post') : null;
        var input = post ? post.querySelector('[data-timeline-reply] input[name="body"]') : null;
        var list = row.closest('#timeline-list');
        if (input) {
            return input;
        }
        if (list) {
            list.setAttribute('tabindex', '-1');
        }

        return list;
    }

    document.addEventListener('close', function (event) {
        var dialog = event.target;
        if (dialog.returnFocusTo && dialog.returnFocusTo.focus) {
            dialog.returnFocusTo.focus();
        }
        dialog.returnFocusTo = null;
    }, true);

    // A click on the backdrop lands on the dialog element itself; a click inside lands on a child.
    // Both ends of the click have to be the backdrop, or a drag that starts on the text and ends
    // outside would close it.
    document.addEventListener('pointerdown', function (event) {
        if (event.target instanceof HTMLDialogElement) {
            event.target.pointerDownOnBackdrop = true;
        }
    });
    document.addEventListener('click', function (event) {
        var dialog = event.target;
        if (dialog instanceof HTMLDialogElement) {
            if (dialog.open && dialog.pointerDownOnBackdrop) {
                dialog.close();
            }
            dialog.pointerDownOnBackdrop = false;
        }
    });

    // --- delete: the row's own confirmation
    document.addEventListener('click', function (event) {
        var link = event.target.closest ? event.target.closest('.timeline-post-delete-confirm-link[data-dialog]') : null;
        if (!link) {
            return;
        }
        var row = rowOf(link);
        var dialog = row ? row.querySelector('dialog[id="' + link.getAttribute('data-dialog') + '"]') : null;
        if (!dialog) {
            return; // the link is the confirm page
        }
        event.preventDefault();
        var error = dialog.querySelector('.timeline-post-delete-error');
        if (error) error.textContent = ''; // a refusal from last time is not this time's
        open(dialog, link);
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form.matches || !form.matches('[data-timeline-delete]')) {
            return;
        }
        event.preventDefault();
        if (form.getAttribute('data-pending') === 'true') {
            return;
        }
        var dialog = form.closest('dialog');
        var row = rowOf(form);
        var id = row ? row.getAttribute('data-timeline-id') : null;
        var button = form.querySelector('button[type="submit"]');
        var loading = dialog ? dialog.querySelector('.timeline-post-delete-loading') : null;
        var error = dialog ? dialog.querySelector('.timeline-post-delete-error') : null;

        form.setAttribute('data-pending', 'true');
        if (button) button.disabled = true;
        if (loading) loading.removeAttribute('hidden');
        if (error) error.textContent = '';

        window.fetch(form.action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: new FormData(form)
        })
            .then(function (response) {
                var type = response.headers.get('Content-Type') || '';
                if (!response.ok || type.indexOf('application/json') !== 0) {
                    throw new Error('refused');
                }

                return response.json();
            })
            .then(function (data) {
                if (!data || data.ok !== true) {
                    throw new Error('refused');
                }
                var rows = id !== null ? document.querySelectorAll('[data-timeline-id="' + id + '"]') : [];
                var target = row ? focusTargetAfterRemoval(row) : null;
                var i;
                var comments;
                if (dialog) dialog.returnFocusTo = null; // the link is leaving with the row
                for (i = 0; i < rows.length; i += 1) {
                    comments = rows[i].closest('.timeline-post-comments');
                    if (comments) bumpGeneration(comments);
                    rows[i].parentNode.removeChild(rows[i]);
                }
                // The dialog left with its row; closing it now restores focus to nothing, so the
                // focus set after it is the one that stays.
                if (dialog && dialog.open) dialog.close();
                if (target) target.focus();
            })
            .catch(function (failure) {
                form.removeAttribute('data-pending');
                if (button) button.disabled = false;
                if (loading) loading.setAttribute('hidden', '');
                // No answer at all (the network): the plain form is the way to find out, and it
                // lands on the confirm page or the deletion's own redirect. An answer that refused
                // stays here, in words; the confirm page would only refuse the same way.
                if (failure instanceof TypeError) {
                    form.submit();
                } else if (error) {
                    error.textContent = form.getAttribute('data-error-text') || '';
                }
            });
    });

    // --- attached images: the page's one lightbox
    document.addEventListener('click', function (event) {
        var link = event.target.closest ? event.target.closest('a[rel="lightbox"]') : null;
        var lightbox = document.querySelector('dialog[data-timeline-lightbox]');
        var image = lightbox ? lightbox.querySelector('img') : null;
        if (!link || !image) {
            return;
        }
        event.preventDefault();
        image.src = link.href;
        open(lightbox, link);
    });
})();
