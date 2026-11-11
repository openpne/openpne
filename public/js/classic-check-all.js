/** The Classic list forms' Check All / Clear All links, which OpenPNE 3 wired with an inline handler. */
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var link = event.target.closest ? event.target.closest('a[data-check-all]') : null;
        var form = link ? link.closest('form') : null;
        if (!form) {
            return;
        }
        event.preventDefault();
        var checked = link.getAttribute('data-check-all') === 'true';
        form.querySelectorAll('input[name="ids[]"]').forEach(function (box) {
            box.checked = checked;
        });
    });
})();
