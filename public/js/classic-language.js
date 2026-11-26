/**
 * The Classic language selector: OpenPNE 3 submitted the form the moment the choice changed
 * (an onchange submit), and this restores that. One delegated listener serves every instance.
 */
(function () {
    'use strict';

    document.addEventListener('change', function (event) {
        var select = event.target;
        if (!select || select.name !== 'locale') {
            return;
        }
        var form = select.closest ? select.closest('form[data-language-switch]') : null;
        if (!form) {
            return;
        }
        // A reload rather than the server's way back: a page that carries a secret is never recorded as the previous URL.
        fetch(form.action, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' }, body: new FormData(form) }).then(
            function (response) {
                if (response.ok) {
                    window.location.reload();
                } else {
                    form.submit();
                }
            },
            function () {
                form.submit();
            }
        );
    });
})();
