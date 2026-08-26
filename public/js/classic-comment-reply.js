/**
 * The Reply link on a topic / event comment (OpenPNE 3 communityTopicComment/_list.php): it quotes
 * the comment as ">>N name" into the comment box and moves the caret there. The anchor points at
 * the form itself, so a browser that never runs this still lands where the reply is written.
 */
(function () {
    'use strict';

    /** The quoted line appended for one comment. */
    function appendQuote(value, number, name) {
        return value + '>>' + number + ' ' + name + '\n';
    }

    /** The comment box a Reply link names, or null when the page has none (then the link is a link). */
    function replyTarget(link, doc) {
        var selector = link.getAttribute('data-comment-reply');

        return selector ? doc.querySelector(selector) : null;
    }

    // `node --test` evaluates this file with a `module` in scope and takes the pure half alone.
    if (typeof module !== 'undefined') {
        module.exports = { appendQuote: appendQuote, replyTarget: replyTarget };

        return;
    }

    document.addEventListener('click', function (event) {
        var link = event.target.closest ? event.target.closest('a[data-comment-reply]') : null;
        if (!link) {
            return;
        }
        var box = replyTarget(link, document);
        if (!box) {
            return;
        }
        event.preventDefault();
        box.value = appendQuote(box.value, link.getAttribute('data-number'), link.getAttribute('data-name'));
        box.focus();
    });
})();
