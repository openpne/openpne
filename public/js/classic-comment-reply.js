/**
 * The Reply link on a topic / event comment (OpenPNE 3 communityTopicComment/_list.php): it quotes
 * the comment as ">>N name" into the comment box and moves the caret there. The anchor points at
 * the form itself, so a browser that never runs this still lands where the reply is written.
 */
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var link = event.target.closest ? event.target.closest('a[data-comment-reply]') : null;
        if (!link) {
            return;
        }
        var box = document.querySelector(link.getAttribute('data-comment-reply'));
        if (!box) {
            return;
        }
        event.preventDefault();
        box.value += '>>' + link.getAttribute('data-number') + ' ' + link.getAttribute('data-name') + '\n';
        box.focus();
    });
})();
